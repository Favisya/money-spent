import AppKit
import UniformTypeIdentifiers
import WebKit

/// The app window content: the local web app in a WKWebView, with native dialogs, uploads and downloads.
final class WebController: NSObject, WKNavigationDelegate, WKUIDelegate, WKDownloadDelegate, WKScriptMessageHandler {
    let webView: WKWebView
    private var allowedHost = "127.0.0.1"
    private var allowedPort: Int?

    /// A new entry by someone else, posted by app.js from the loaded app page.
    var onNotify: ((_ id: String, _ title: String, _ body: String) -> Void)?
    /// A button on one of the built-in pages (chooser, offline): ["action": "local" | "connect" | "retry", "url": ...].
    var onAction: (([String: String]) -> Void)?
    /// The app page could not be loaded (the other Mac is off or sharing is disabled there).
    var onLoadFailed: ((URL) -> Void)?
    var view: NSView { webView }

    override init() {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default()
        webView = WKWebView(frame: .zero, configuration: configuration)
        super.init()
        let proxy = WeakMessageHandler(self)
        configuration.userContentController.add(proxy, name: "notify")
        configuration.userContentController.add(proxy, name: "app")
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true
    }

    func open(_ url: URL) {
        allowedHost = url.host ?? "127.0.0.1"
        allowedPort = url.port
        webView.load(URLRequest(url: url))
    }

    func showStatus(_ text: String) {
        webView.loadHTMLString(Self.page(body: "<div class=\"dot\"></div><p>\(Self.escape(text))</p>"), baseURL: nil)
    }

    /// First launch without local data: keep the books on this Mac or join the one running on another Mac.
    func showChooser(found: [Bonjour.Found], note: String? = nil) {
        let list = found.isEmpty
            ? "<p class=\"muted\">\(Self.text("chooser.searching"))</p>"
            : found.map { item in
                "<button class=\"choice\" data-url=\"\(Self.escape(item.url.absoluteString))\">\(Self.escape(item.name))"
                    + "<span>\(Self.escape(item.url.host ?? ""))</span></button>"
            }.joined()
        let body = """
        <h1>\(Self.text("chooser.title"))</h1>
        \(note.map { "<p class=\"warn\">\(Self.escape($0))</p>" } ?? "")
        <button class="primary" data-action="local">\(Self.text("chooser.local"))</button>
        <h2>\(Self.text("chooser.orConnect"))</h2>
        <div class="list">\(list)</div>
        <form id="manual">
        <input id="address" placeholder="\(Self.text("chooser.addressPlaceholder"))" autocomplete="off">
        <button type="submit">\(Self.text("chooser.connect"))</button></form>
        <p class="muted">\(Self.text("chooser.hint"))</p>
        <script>
        const post = (m) => window.webkit.messageHandlers.app.postMessage(m);
        document.querySelectorAll('[data-action]').forEach((b) => b.onclick = () => post({action: b.dataset.action}));
        document.querySelectorAll('[data-url]').forEach((b) => b.onclick = () => post({action: 'connect', url: b.dataset.url}));
        document.getElementById('manual').onsubmit = (e) => {
            e.preventDefault();
            post({action: 'connect', url: document.getElementById('address').value.trim()});
        };
        </script>
        """
        webView.loadHTMLString(Self.page(body: body, script: true), baseURL: nil)
    }

    func showOffline(_ url: URL) {
        let body = """
        <h1>\(Self.text("offline.title", url.host ?? L10n.text("offline.thatMac")))</h1>
        <p class="muted">\(Self.text("offline.hint"))</p>
        <button class="primary" data-action="retry">\(Self.text("offline.retry"))</button>
        <button data-action="choose">\(Self.text("offline.choose"))</button>
        <script>
        document.querySelectorAll('[data-action]').forEach((b) =>
            b.onclick = () => window.webkit.messageHandlers.app.postMessage({action: b.dataset.action}));
        </script>
        """
        webView.loadHTMLString(Self.page(body: body, script: true), baseURL: nil)
    }

    // MARK: - Messages from pages

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        guard let payload = message.body as? [String: Any], message.frameInfo.isMainFrame else { return }

        let strings = payload.compactMapValues { $0 as? String }
        let scheme = message.frameInfo.request.url?.scheme ?? ""
        let origin = message.frameInfo.securityOrigin

        switch message.name {
        case "notify" where scheme == "http" && origin.host == allowedHost
            && (allowedPort == nil || origin.port == allowedPort):
            onNotify?(strings["id"] ?? "", strings["title"] ?? "", strings["body"] ?? "")
        case "app" where scheme != "http" && scheme != "https":
            onAction?(strings)
        default:
            break
        }
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        let failed = (error as NSError).userInfo[NSURLErrorFailingURLErrorKey] as? URL
        if let failed, failed.scheme == "http" { onLoadFailed?(failed) }
    }

    func showError(_ text: String, logs: String) {
        let body = """
        <h1>\(Self.text("error.title"))</h1>
        <p>\(Self.escape(text))</p>
        <p class="muted">\(Self.text("error.logs")) <code>\(Self.escape(logs))</code></p>
        <p class="muted">\(Self.text("error.hint"))</p>
        """
        webView.loadHTMLString(Self.page(body: body), baseURL: nil)
    }

    // MARK: - Navigation

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationAction: WKNavigationAction,
        decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
    ) {
        guard let url = navigationAction.request.url else {
            decisionHandler(.cancel)
            return
        }

        if url.scheme == "about" || url.scheme == "data" || isLocal(url) {
            decisionHandler(navigationAction.shouldPerformDownload ? .download : .allow)
            return
        }

        if url.scheme == "http" || url.scheme == "https" || url.scheme == "mailto" {
            NSWorkspace.shared.open(url)
        }
        decisionHandler(.cancel)
    }

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationResponse: WKNavigationResponse,
        decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void
    ) {
        let disposition = (navigationResponse.response as? HTTPURLResponse)?
            .value(forHTTPHeaderField: "Content-Disposition")?
            .lowercased() ?? ""

        if disposition.hasPrefix("attachment") || !navigationResponse.canShowMIMEType {
            decisionHandler(.download)
            return
        }

        decisionHandler(.allow)
    }

    func webView(_ webView: WKWebView, navigationAction: WKNavigationAction, didBecome download: WKDownload) {
        download.delegate = self
    }

    func webView(_ webView: WKWebView, navigationResponse: WKNavigationResponse, didBecome download: WKDownload) {
        download.delegate = self
    }

    private func isLocal(_ url: URL) -> Bool {
        url.scheme == "http" && url.host == allowedHost && (allowedPort == nil || url.port == allowedPort)
    }

    // MARK: - Downloads

    func download(
        _ download: WKDownload,
        decideDestinationUsing response: URLResponse,
        suggestedFilename: String,
        completionHandler: @escaping (URL?) -> Void
    ) {
        let panel = NSSavePanel()
        panel.nameFieldStringValue = suggestedFilename
        panel.canCreateDirectories = true
        panel.directoryURL = FileManager.default.urls(for: .downloadsDirectory, in: .userDomainMask).first

        guard let window = webView.window else {
            completionHandler(panel.runModal() == .OK ? panel.url : nil)
            return
        }

        panel.beginSheetModal(for: window) { result in
            guard result == .OK, let url = panel.url else {
                completionHandler(nil)
                return
            }
            try? FileManager.default.removeItem(at: url)
            completionHandler(url)
        }
    }

    func download(_ download: WKDownload, didFailWithError error: Error, resumeData: Data?) {
        alert(L10n.text("alert.saveFailed"), error.localizedDescription)
    }

    // MARK: - JavaScript dialogs and file inputs

    func webView(
        _ webView: WKWebView,
        runJavaScriptAlertPanelWithMessage message: String,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping () -> Void
    ) {
        alert(message, nil)
        completionHandler()
    }

    func webView(
        _ webView: WKWebView,
        runJavaScriptConfirmPanelWithMessage message: String,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping (Bool) -> Void
    ) {
        let panel = NSAlert()
        panel.messageText = message
        panel.addButton(withTitle: L10n.text("button.yes"))
        panel.addButton(withTitle: L10n.text("button.cancel"))
        completionHandler(panel.runModal() == .alertFirstButtonReturn)
    }

    func webView(
        _ webView: WKWebView,
        runOpenPanelWith parameters: WKOpenPanelParameters,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping ([URL]?) -> Void
    ) {
        let panel = NSOpenPanel()
        panel.canChooseFiles = true
        panel.canChooseDirectories = false
        panel.allowsMultipleSelection = parameters.allowsMultipleSelection
        panel.allowedContentTypes = [.plainText, .text]
        completionHandler(panel.runModal() == .OK ? panel.urls : nil)
    }

    func webView(
        _ webView: WKWebView,
        createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction,
        windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        if let url = navigationAction.request.url {
            if isLocal(url) {
                webView.load(URLRequest(url: url))
            } else {
                NSWorkspace.shared.open(url)
            }
        }
        return nil
    }

    private func alert(_ title: String, _ detail: String?) {
        let panel = NSAlert()
        panel.messageText = title
        panel.informativeText = detail ?? ""
        panel.runModal()
    }

    // MARK: - Built-in pages

    private static func escape(_ text: String) -> String {
        text
            .replacingOccurrences(of: "&", with: "&amp;")
            .replacingOccurrences(of: "<", with: "&lt;")
            .replacingOccurrences(of: ">", with: "&gt;")
            .replacingOccurrences(of: "\"", with: "&quot;")
    }

    /// A localized text, filled in and escaped for the built-in pages.
    private static func text(_ key: String, _ arguments: CVarArg...) -> String {
        escape(arguments.isEmpty ? L10n.text(key) : L10n.text(key, arguments: arguments))
    }

    private static func page(body: String, script: Bool = false) -> String {
        let scripts = script ? "; script-src 'unsafe-inline'" : ""
        return """
        <!doctype html><html lang="\(L10n.language)"><head><meta charset="utf-8">
        <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'\(scripts)">
        <style>
        :root { color-scheme: light dark; --ink: #1c1c1f; --muted: #63636b; --accent: #0f766e; --bg: #f6f6f4; }
        @media (prefers-color-scheme: dark) { :root { --ink: #ececee; --muted: #a0a0a8; --accent: #3fb8a9; --bg: #121214; } }
        body { margin: 0; min-height: 100vh; display: grid; place-content: center; text-align: center;
               font: 15px/1.5 -apple-system, sans-serif; color: var(--ink); background: var(--bg); padding: 0 24px; }
        h1 { font-size: 1.2rem; } .muted { color: var(--muted); font-size: 0.88rem; }
        code { font: 0.85em ui-monospace, Menlo, monospace; word-break: break-all; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); margin: 0 auto 12px;
               animation: pulse 1s ease-in-out infinite alternate; }
        @keyframes pulse { from { opacity: 0.25; } to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .dot { animation: none; } }
        h2 { font-size: 0.95rem; font-weight: 600; margin: 1.6rem 0 0.6rem; color: var(--muted); }
        button { font: inherit; padding: 0.6rem 1rem; border-radius: 6px; border: 1px solid color-mix(in srgb, var(--ink) 20%, transparent);
                 background: transparent; color: var(--ink); cursor: pointer; margin: 0.25rem; }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
        .list { display: grid; gap: 0.4rem; justify-items: center; }
        .choice { min-width: 18rem; display: grid; } .choice span { font-size: 0.8rem; color: var(--muted); }
        input { font: inherit; padding: 0.55rem 0.7rem; border-radius: 6px; width: 20rem; max-width: 70vw;
                border: 1px solid color-mix(in srgb, var(--ink) 20%, transparent); background: transparent; color: var(--ink); }
        form { margin-top: 1rem; } .warn { color: #b3261e; }
        </style></head><body>\(body)</body></html>
        """
    }
}

/// WKUserContentController keeps its handlers strongly; this breaks the cycle with the controller.
private final class WeakMessageHandler: NSObject, WKScriptMessageHandler {
    private weak var target: WKScriptMessageHandler?

    init(_ target: WKScriptMessageHandler) {
        self.target = target
    }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        target?.userContentController(controller, didReceive: message)
    }
}
