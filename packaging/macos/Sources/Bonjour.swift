import Foundation

/// Announces this Mac on the home network while sharing is on, and finds other Macs that do.
final class Bonjour: NSObject, NetServiceDelegate, NetServiceBrowserDelegate {
    static let serviceType = "_moneyspent._tcp."

    struct Found: Equatable {
        let name: String
        let url: URL
    }

    var onChange: (([Found]) -> Void)?

    private var published: NetService?
    private var browser: NetServiceBrowser?
    private var resolving: [NetService] = []
    private(set) var found: [Found] = []

    func publish(port: Int) {
        unpublish()
        let name = L10n.text("bonjour.serviceName", Host.current().localizedName ?? "Mac")
        let service = NetService(domain: "local.", type: Self.serviceType, name: name, port: Int32(port))
        service.publish()
        published = service
    }

    func unpublish() {
        published?.stop()
        published = nil
    }

    func browse() {
        stopBrowsing()
        found = []
        let browser = NetServiceBrowser()
        browser.delegate = self
        browser.searchForServices(ofType: Self.serviceType, inDomain: "local.")
        self.browser = browser
    }

    func stopBrowsing() {
        browser?.stop()
        browser = nil
        resolving.forEach { $0.stop() }
        resolving = []
    }

    func netServiceBrowser(_ browser: NetServiceBrowser, didFind service: NetService, moreComing: Bool) {
        service.delegate = self
        resolving.append(service)
        service.resolve(withTimeout: 5)
    }

    func netServiceBrowser(_ browser: NetServiceBrowser, didRemove service: NetService, moreComing: Bool) {
        found.removeAll { $0.name == service.name }
        onChange?(found)
    }

    func netServiceDidResolveAddress(_ sender: NetService) {
        guard let host = sender.hostName?.trimmingCharacters(in: CharacterSet(charactersIn: ".")), sender.port > 0,
              let url = URL(string: "http://\(host):\(sender.port)") else { return }

        found.removeAll { $0.name == sender.name }
        found.append(Found(name: sender.name, url: url))
        onChange?(found)
    }
}
