import AppKit
import WebKit

final class AppDelegate: NSObject, NSApplicationDelegate {
    private let runtime = Runtime()
    private var window: NSWindow!
    private var web: WebController!
    private var ready = false

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
        window = NSWindow(
            contentRect: NSRect(x: 0, y: 0, width: 1240, height: 860),
            styleMask: [.titled, .closable, .miniaturizable, .resizable],
            backing: .buffered,
            defer: false
        )
        window.title = "Траты"
        window.minSize = NSSize(width: 420, height: 500)
        window.contentView = web.view
        window.setFrameAutosaveName("MainWindow")
        if !window.setFrameUsingName("MainWindow") {
            window.center()
        }
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)

        web.showStatus("Запускаю…")
        runtime.onStatus = { [weak self] text in self?.web.showStatus(text) }

        DispatchQueue.global(qos: .userInitiated).async { [runtime] in
            do {
                try runtime.start()
                DispatchQueue.main.async {
                    self.ready = true
                    self.web.open(runtime.baseURL)
                }
            } catch {
                DispatchQueue.main.async {
                    self.web.showError(error.localizedDescription, logs: runtime.dataDirectory.appendingPathComponent("logs").path)
                }
            }
        }
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
        true
    }

    func applicationShouldTerminate(_ sender: NSApplication) -> NSApplication.TerminateReply {
        DispatchQueue.global(qos: .userInitiated).async { [runtime] in
            runtime.stop()
            DispatchQueue.main.async { NSApp.reply(toApplicationShouldTerminate: true) }
        }
        return .terminateLater
    }

    // MARK: - Menu

    private func buildMenu() {
        let main = NSMenu()

        let app = NSMenu()
        app.addItem(withTitle: "О программе «Траты»", action: #selector(NSApplication.orderFrontStandardAboutPanel(_:)), keyEquivalent: "")
        app.addItem(.separator())
        app.addItem(withTitle: "Открыть папку с данными", action: #selector(openDataFolder), keyEquivalent: "")
        app.addItem(.separator())
        app.addItem(withTitle: "Скрыть «Траты»", action: #selector(NSApplication.hide(_:)), keyEquivalent: "h")
        app.addItem(withTitle: "Завершить «Траты»", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        main.addItem(submenu: app, title: "Траты")

        let edit = NSMenu(title: "Правка")
        edit.addItem(withTitle: "Отменить", action: Selector(("undo:")), keyEquivalent: "z")
        edit.addItem(withTitle: "Повторить", action: Selector(("redo:")), keyEquivalent: "Z")
        edit.addItem(.separator())
        edit.addItem(withTitle: "Вырезать", action: #selector(NSText.cut(_:)), keyEquivalent: "x")
        edit.addItem(withTitle: "Скопировать", action: #selector(NSText.copy(_:)), keyEquivalent: "c")
        edit.addItem(withTitle: "Вставить", action: #selector(NSText.paste(_:)), keyEquivalent: "v")
        edit.addItem(withTitle: "Выбрать все", action: #selector(NSText.selectAll(_:)), keyEquivalent: "a")
        main.addItem(submenu: edit, title: "Правка")

        let view = NSMenu(title: "Вид")
        view.addItem(withTitle: "Обзор", action: #selector(goHome), keyEquivalent: "1")
        view.addItem(withTitle: "Аналитика", action: #selector(goAnalytics), keyEquivalent: "2")
        view.addItem(withTitle: "Импорт", action: #selector(goImport), keyEquivalent: "3")
        view.addItem(.separator())
        view.addItem(withTitle: "Назад", action: #selector(goBack), keyEquivalent: "[")
        view.addItem(withTitle: "Вперёд", action: #selector(goForward), keyEquivalent: "]")
        view.addItem(withTitle: "Обновить", action: #selector(reload), keyEquivalent: "r")
        view.addItem(.separator())
        view.addItem(withTitle: "Крупнее", action: #selector(zoomIn), keyEquivalent: "+")
        view.addItem(withTitle: "Мельче", action: #selector(zoomOut), keyEquivalent: "-")
        view.addItem(withTitle: "Обычный размер", action: #selector(zoomReset), keyEquivalent: "0")
        main.addItem(submenu: view, title: "Вид")

        let windowMenu = NSMenu(title: "Окно")
        windowMenu.addItem(withTitle: "Свернуть", action: #selector(NSWindow.performMiniaturize(_:)), keyEquivalent: "m")
        windowMenu.addItem(withTitle: "Закрыть", action: #selector(NSWindow.performClose(_:)), keyEquivalent: "w")
        main.addItem(submenu: windowMenu, title: "Окно")
        NSApp.windowsMenu = windowMenu

        NSApp.mainMenu = main
    }

    @objc private func openDataFolder() { NSWorkspace.shared.open(runtime.dataDirectory) }
    @objc private func goHome() { navigate("/") }
    @objc private func goAnalytics() { navigate("/analytics") }
    @objc private func goImport() { navigate("/import") }
    @objc private func goBack() { _ = web.webView.goBack() }
    @objc private func goForward() { _ = web.webView.goForward() }
    @objc private func reload() { if ready { _ = web.webView.reload() } }
    @objc private func zoomIn() { web.webView.pageZoom = min(2.0, web.webView.pageZoom + 0.1) }
    @objc private func zoomOut() { web.webView.pageZoom = max(0.6, web.webView.pageZoom - 0.1) }
    @objc private func zoomReset() { web.webView.pageZoom = 1.0 }

    private func navigate(_ path: String) {
        guard ready else { return }
        web.open(runtime.baseURL.appendingPathComponent(path))
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
