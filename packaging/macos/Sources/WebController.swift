import AppKit
import UniformTypeIdentifiers
import WebKit

/// The app window content: the local web app in a WKWebView, with native dialogs, uploads and downloads.
final class WebController: NSObject, WKNavigationDelegate, WKUIDelegate, WKDownloadDelegate {
    let webView: WKWebView
    private var allowedHost = "127.0.0.1"
    private var allowedPort: Int?

    var view: NSView { webView }

    override init() {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default()
        webView = WKWebView(frame: .zero, configuration: configuration)
        super.init()
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true
    }

    func open(_ url: URL) {
        allowedPort = url.port
        webView.load(URLRequest(url: url))
    }

    func showStatus(_ text: String) {
        webView.loadHTMLString(Self.page(body: "<div class=\"dot\"></div><p>\(Self.escape(text))</p>"), baseURL: nil)
    }

    func showError(_ text: String, logs: String) {
        let body = """
        <h1>Не удалось запустить</h1>
        <p>\(Self.escape(text))</p>
        <p class="muted">Журналы: <code>\(Self.escape(logs))</code></p>
        <p class="muted">Закрой приложение и открой снова. Если повторится — пришли журналы.</p>
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
        alert("Не удалось сохранить файл", error.localizedDescription)
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
        panel.addButton(withTitle: "Да")
        panel.addButton(withTitle: "Отмена")
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

    private static func page(body: String) -> String {
        """
        <!doctype html><html lang="ru"><head><meta charset="utf-8">
        <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'">
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
        </style></head><body>\(body)</body></html>
        """
    }
}
