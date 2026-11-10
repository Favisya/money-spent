import Foundation
import UserNotifications

/// macOS notifications for entries other people record ("Алиса: 9 700 AMD").
final class Notifier: NSObject, UNUserNotificationCenterDelegate {
    private let center = UNUserNotificationCenter.current()
    private var seen = Set<String>()

    override init() {
        super.init()
        center.delegate = self
    }

    func show(id: String, title: String, body: String) {
        guard !id.isEmpty, seen.insert(id).inserted else { return }

        center.requestAuthorization(options: [.alert, .sound]) { [center] granted, _ in
            guard granted else { return }

            let content = UNMutableNotificationContent()
            content.title = String(title.prefix(120))
            content.body = String(body.prefix(240))
            content.sound = .default
            center.add(UNNotificationRequest(identifier: id, content: content, trigger: nil))
        }
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        completionHandler([.banner, .sound])
    }
}
