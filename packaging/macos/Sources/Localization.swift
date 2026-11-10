import Foundation

/// Texts of the native wrapper: Russian on Russian-speaking systems, English everywhere else.
///
/// The language is picked from the first preferred system language rather than by the bundle's own matching,
/// so that Ukrainian, Belarusian and Kazakh systems get Russian instead of falling back to English.
enum L10n {
    private static let russianSpeaking: Set<String> = ["ru", "uk", "be", "kk"]

    /// "ru" or "en": the lproj folder to read and the `lang` of the built-in HTML pages.
    static let language: String = {
        let preferred = Locale.preferredLanguages.first ?? ""
        let code = preferred.prefix { $0 != "-" && $0 != "_" }.lowercased()
        return russianSpeaking.contains(code) ? "ru" : "en"
    }()

    private static let bundle: Bundle = Bundle.main.path(forResource: language, ofType: "lproj")
        .flatMap(Bundle.init(path:)) ?? .main

    /// The text for `key` from Localizable.strings; the key itself when it is missing.
    static func text(_ key: String) -> String {
        bundle.localizedString(forKey: key, value: nil, table: nil)
    }

    /// The text for `key` with `%@` / `%d` placeholders filled in.
    static func text(_ key: String, _ arguments: CVarArg...) -> String {
        text(key, arguments: arguments)
    }

    static func text(_ key: String, arguments: [CVarArg]) -> String {
        String(format: text(key), locale: Locale(identifier: language), arguments: arguments)
    }
}
