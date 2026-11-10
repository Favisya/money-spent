import AppKit
import WebKit

/// Two ways to run: keep the books on this Mac (bundled PostgreSQL and PHP, optionally shared with the home network)
/// or connect to another Mac that shares its books.
final class AppDelegate: NSObject, NSApplicationDelegate, NSMenuItemValidation {
    private enum Mode: String {
        case local
        case remote
    }

    private enum Key {
        static let mode = "mode"
        static let remoteURL = "remoteURL"
        static let sharing = "sharing"
    }

    private let runtime = Runtime()
    private let bonjour = Bonjour()
    private let notifier = Notifier()
    private let defaults = UserDefaults.standard
    private var window: NSWindow!
    private var web: WebController!
    private var mode: Mode?
    private var home: URL?
    private var busy = false

    func applicationDidFinishLaunching(_ notification: Notification) {
        if let bundleID = Bundle.main.bundleIdentifier,
           let other = NSRunningApplication.runningApplications(withBundleIdentifier: bundleID)
               .first(where: { $0 != NSRunningApplication.current }) {
            other.activate(options: [])
            NSApp.terminate(nil)
            return
        }

        buildMenu()
        web = WebController()
        web.onNotify = { [notifier] id, title, body in notifier.show(id: id, title: title, body: body) }
        web.onAction = { [weak self] action in self?.handle(action) }
        web.onLoadFailed = { [weak self] url in self?.loadFailed(url) }
        bonjour.onChange = { [weak self] found in
            guard let self, self.mode == nil else { return }
            self.web.showChooser(found: found)
        }
        runtime.onStatus = { [weak self] text in self?.web.showStatus(text) }

        window = NSWindow(
            contentRect: NSRect(x: 0, y: 0, width: 1240, height: 860),
            styleMask: [.titled, .closable, .miniaturizable, .resizable],
            backing: .buffered,
            defer: false
        )
        window.title = L10n.text("app.name")
        window.minSize = NSSize(width: 420, height: 500)
        window.contentView = web.view
        window.setFrameAutosaveName("MainWindow")
        if !window.setFrameUsingName("MainWindow") {
            window.center()
        }
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)

        let saved = defaults.string(forKey: Key.mode).flatMap(Mode.init(rawValue:))
        if saved == .remote, let url = defaults.url(forKey: Key.remoteURL) {
            startRemote(url)
        } else if saved == .local || runtime.hasLocalData {
            startLocal()
        } else {
            showChooser()
        }
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
        true
    }

    func applicationShouldTerminate(_ sender: NSApplication) -> NSApplication.TerminateReply {
        bonjour.unpublish()
        bonjour.stopBrowsing()
        DispatchQueue.global(qos: .userInitiated).async { [runtime] in
            runtime.stop()
            DispatchQueue.main.async { NSApp.reply(toApplicationShouldTerminate: true) }
        }
        return .terminateLater
    }

    // MARK: - Modes

    private func showChooser(note: String? = nil) {
        mode = nil
        home = nil
        web.showChooser(found: bonjour.found, note: note)
        bonjour.browse()
    }

    private func startLocal() {
        mode = .local
        bonjour.stopBrowsing()
        defaults.set(Mode.local.rawValue, forKey: Key.mode)
        web.showStatus(L10n.text("status.starting"))
        busy = true
        let sharing = defaults.bool(forKey: Key.sharing)

        DispatchQueue.global(qos: .userInitiated).async { [runtime] in
            do {
                try runtime.start(sharing: sharing)
                DispatchQueue.main.async {
                    self.busy = false
                    self.home = runtime.baseURL
                    if sharing { self.bonjour.publish(port: runtime.webPort) }
                    self.web.open(runtime.baseURL)
                }
            } catch {
                DispatchQueue.main.async {
                    self.busy = false
                    self.web.showError(
                        error.localizedDescription,
                        logs: runtime.dataDirectory.appendingPathComponent("logs").path
                    )
                }
            }
        }
    }

    private func startRemote(_ url: URL) {
        mode = .remote
        home = url
        bonjour.stopBrowsing()
        defaults.set(Mode.remote.rawValue, forKey: Key.mode)
        defaults.set(url, forKey: Key.remoteURL)
        web.open(url)
    }

    private func handle(_ action: [String: String]) {
        switch action["action"] {
        case "local":
            startLocal()
        case "connect":
            guard let url = Self.remoteURL(from: action["url"] ?? "") else {
                showChooser(note: L10n.text("chooser.badAddress"))
                return
            }
            startRemote(url)
        case "retry":
            if let home { web.open(home) }
        case "choose":
            showChooser()
        default:
            break
        }
    }

    private func loadFailed(_ url: URL) {
        guard mode == .remote, let home, url.host == home.host else { return }
        web.showOffline(home)
    }

    /// Only plain http addresses on the home network: a Bonjour name or a private IPv4 address.
    private static func remoteURL(from text: String) -> URL? {
        var address = text.trimmingCharacters(in: .whitespacesAndNewlines)
        if !address.contains("://") { address = "http://" + address }

        guard let url = URL(string: address), url.scheme == "http", let host = url.host,
              host.range(of: Runtime.lanHostsPattern, options: .regularExpression) != nil,
              host != "localhost", host != "127.0.0.1" else { return nil }

        var parts = URLComponents()
        parts.scheme = "http"
        parts.host = host
        parts.port = url.port ?? Runtime.preferredPort
        return parts.url
    }

    // MARK: - Sharing

    @objc private func toggleSharing() {
        guard mode == .local, !busy else { return }
        let enable = !runtime.sharing
        busy = true

        DispatchQueue.global(qos: .userInitiated).async { [runtime] in
            do {
                try runtime.setSharing(enable)
                DispatchQueue.main.async {
                    self.busy = false
                    self.defaults.set(enable, forKey: Key.sharing)
                    if enable {
                        self.bonjour.publish(port: runtime.webPort)
                        self.navigate("/sharing")
                    } else {
                        self.bonjour.unpublish()
                        self.web.webView.reload()
                    }
                }
            } catch {
                DispatchQueue.main.async {
                    self.busy = false
                    self.alert(L10n.text("alert.sharingFailed"), error.localizedDescription)
                }
            }
        }
    }

    @objc private func connectElsewhere() {
        let question = NSAlert()
        let remote = mode == .remote
        question.messageText = L10n.text(remote ? "disconnect.title" : "connect.title")
        question.informativeText = L10n.text(remote ? "disconnect.detail" : "connect.detail")
        question.addButton(withTitle: L10n.text("button.yes"))
        question.addButton(withTitle: L10n.text("button.cancel"))
        guard question.runModal() == .alertFirstButtonReturn else { return }

        defaults.removeObject(forKey: Key.mode)
        bonjour.unpublish()
        web.showStatus(L10n.text("status.stopping"))
        DispatchQueue.global(qos: .userInitiated).async { [runtime] in
            runtime.stop()
            DispatchQueue.main.async { self.showChooser() }
        }
    }

    func validateMenuItem(_ item: NSMenuItem) -> Bool {
        switch item.action {
        case #selector(toggleSharing):
            item.state = runtime.sharing ? .on : .off
            return mode == .local && !busy && runtime.isRunning
        case #selector(goSharing), #selector(openDataFolder):
            return mode == .local
        case #selector(connectElsewhere):
            item.title = L10n.text(mode == .remote ? "menu.disconnect" : "menu.connect")
            return mode != nil && !busy
        case #selector(goHome), #selector(goAnalytics), #selector(goImport), #selector(reload):
            return home != nil
        default:
            return true
        }
    }

    // MARK: - Menu

    private func buildMenu() {
        let main = NSMenu()

        let app = NSMenu()
        app.addItem(
            withTitle: L10n.text("menu.about"),
            action: #selector(NSApplication.orderFrontStandardAboutPanel(_:)),
            keyEquivalent: ""
        )
        app.addItem(.separator())
        app.addItem(withTitle: L10n.text("menu.allowConnections"), action: #selector(toggleSharing), keyEquivalent: "")
        app.addItem(withTitle: L10n.text("menu.sharing"), action: #selector(goSharing), keyEquivalent: "")
        app.addItem(withTitle: L10n.text("menu.connect"), action: #selector(connectElsewhere), keyEquivalent: "")
        app.addItem(.separator())
        app.addItem(withTitle: L10n.text("menu.openDataFolder"), action: #selector(openDataFolder), keyEquivalent: "")
        app.addItem(.separator())
        app.addItem(withTitle: L10n.text("menu.hide"), action: #selector(NSApplication.hide(_:)), keyEquivalent: "h")
        app.addItem(
            withTitle: L10n.text("menu.quit"),
            action: #selector(NSApplication.terminate(_:)),
            keyEquivalent: "q"
        )
        main.addItem(submenu: app, title: L10n.text("app.name"))

        let edit = NSMenu(title: L10n.text("menu.edit"))
        edit.addItem(withTitle: L10n.text("menu.undo"), action: Selector(("undo:")), keyEquivalent: "z")
        edit.addItem(withTitle: L10n.text("menu.redo"), action: Selector(("redo:")), keyEquivalent: "Z")
        edit.addItem(.separator())
        edit.addItem(withTitle: L10n.text("menu.cut"), action: #selector(NSText.cut(_:)), keyEquivalent: "x")
        edit.addItem(withTitle: L10n.text("menu.copy"), action: #selector(NSText.copy(_:)), keyEquivalent: "c")
        edit.addItem(withTitle: L10n.text("menu.paste"), action: #selector(NSText.paste(_:)), keyEquivalent: "v")
        edit.addItem(
            withTitle: L10n.text("menu.selectAll"),
            action: #selector(NSText.selectAll(_:)),
            keyEquivalent: "a"
        )
        main.addItem(submenu: edit, title: edit.title)

        let view = NSMenu(title: L10n.text("menu.view"))
        view.addItem(withTitle: L10n.text("menu.overview"), action: #selector(goHome), keyEquivalent: "1")
        view.addItem(withTitle: L10n.text("menu.analytics"), action: #selector(goAnalytics), keyEquivalent: "2")
        view.addItem(withTitle: L10n.text("menu.import"), action: #selector(goImport), keyEquivalent: "3")
        view.addItem(.separator())
        view.addItem(withTitle: L10n.text("menu.back"), action: #selector(goBack), keyEquivalent: "[")
        view.addItem(withTitle: L10n.text("menu.forward"), action: #selector(goForward), keyEquivalent: "]")
        view.addItem(withTitle: L10n.text("menu.reload"), action: #selector(reload), keyEquivalent: "r")
        view.addItem(.separator())
        view.addItem(withTitle: L10n.text("menu.zoomIn"), action: #selector(zoomIn), keyEquivalent: "+")
        view.addItem(withTitle: L10n.text("menu.zoomOut"), action: #selector(zoomOut), keyEquivalent: "-")
        view.addItem(withTitle: L10n.text("menu.actualSize"), action: #selector(zoomReset), keyEquivalent: "0")
        main.addItem(submenu: view, title: view.title)

        let windowMenu = NSMenu(title: L10n.text("menu.window"))
        windowMenu.addItem(
            withTitle: L10n.text("menu.minimize"),
            action: #selector(NSWindow.performMiniaturize(_:)),
            keyEquivalent: "m"
        )
        windowMenu.addItem(
            withTitle: L10n.text("menu.close"),
            action: #selector(NSWindow.performClose(_:)),
            keyEquivalent: "w"
        )
        main.addItem(submenu: windowMenu, title: windowMenu.title)
        NSApp.windowsMenu = windowMenu

        NSApp.mainMenu = main
    }

    @objc private func openDataFolder() { NSWorkspace.shared.open(runtime.dataDirectory) }
    @objc private func goHome() { navigate("/") }
    @objc private func goAnalytics() { navigate("/analytics") }
    @objc private func goImport() { navigate("/import") }
    @objc private func goSharing() { navigate("/sharing") }
    @objc private func goBack() { _ = web.webView.goBack() }
    @objc private func goForward() { _ = web.webView.goForward() }
    @objc private func reload() { if home != nil { _ = web.webView.reload() } }
    @objc private func zoomIn() { web.webView.pageZoom = min(2.0, web.webView.pageZoom + 0.1) }
    @objc private func zoomOut() { web.webView.pageZoom = max(0.6, web.webView.pageZoom - 0.1) }
    @objc private func zoomReset() { web.webView.pageZoom = 1.0 }

    private func navigate(_ path: String) {
        guard let home else { return }
        web.open(home.appendingPathComponent(path))
    }

    private func alert(_ title: String, _ detail: String) {
        let panel = NSAlert()
        panel.messageText = title
        panel.informativeText = detail
        panel.runModal()
    }
}

extension NSMenu {
    func addItem(submenu: NSMenu, title: String) {
        let item = NSMenuItem(title: title, action: nil, keyEquivalent: "")
        item.submenu = submenu
        addItem(item)
    }
}

let application = NSApplication.shared
let delegate = AppDelegate()
application.delegate = delegate
application.setActivationPolicy(.regular)
application.run()
