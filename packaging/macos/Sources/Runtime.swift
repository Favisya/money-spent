import Foundation
import SystemConfiguration

/// Starts and stops the bundled PostgreSQL, the PHP web server and the scheduler worker.
final class Runtime {
    enum Failure: LocalizedError {
        case step(String, String)

        var errorDescription: String? {
            switch self {
            case let .step(name, detail):
                return "\(name): \(detail)"
            }
        }
    }

    private let resources: URL
    private let support: URL
    private let logs: URL
    private let fileManager = FileManager.default
    private var server: Process?
    private var worker: Process?
    private var stopping = false
    private var secrets: Secrets!
    private(set) var webPort: Int = 0
    /// Whether other Macs on the network may connect; the web server then listens on all interfaces.
    private(set) var sharing = false
    private(set) var sharingURLs: [String] = []
    static let preferredPort = 8765
    private var dbPort: Int = 0

    var onStatus: ((String) -> Void)?

    init() {
        resources = Bundle.main.resourceURL!
        let base = fileManager.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        support = base.appendingPathComponent("MoneySpent", isDirectory: true)
        logs = support.appendingPathComponent("logs", isDirectory: true)
    }

    var dataDirectory: URL { support }
    var hasLocalData: Bool { fileManager.fileExists(atPath: pgData.appendingPathComponent("PG_VERSION").path) }
    private(set) var isRunning = false
    var baseURL: URL { URL(string: "http://127.0.0.1:\(webPort)")! }

    private var runtimeDir: URL { resources.appendingPathComponent("runtime", isDirectory: true) }
    private var appDir: URL { resources.appendingPathComponent("app", isDirectory: true) }
    private var pgData: URL { support.appendingPathComponent("pgdata", isDirectory: true) }
    private var varDir: URL { support.appendingPathComponent("var", isDirectory: true) }
    private var php: String { runtimeDir.appendingPathComponent("php/bin/php").path }

    private func pg(_ tool: String) -> String {
        runtimeDir.appendingPathComponent("pgsql/bin/\(tool)").path
    }

    // MARK: - Lifecycle

    func start(sharing: Bool) throws {
        stopping = false
        self.sharing = sharing
        try prepareDirectories()
        secrets = try Secrets.load(from: support.appendingPathComponent("secrets.json"))
        dbPort = try Runtime.freePort()
        webPort = Runtime.isFree(Runtime.preferredPort) ? Runtime.preferredPort : try Runtime.freePort()
        sharingURLs = sharing ? Runtime.lanURLs(port: webPort) : []

        status(L10n.text("status.preparingDatabase"))
        try initDatabaseIfNeeded()
        try startDatabase()

        status(L10n.text("status.updatingApp"))
        try prepareApplication()

        status(L10n.text("status.starting"))
        try startServer()
        startWorker()
        fetchRatesInBackground()
        try waitForServer()
        isRunning = true
    }

    /// Re-binds the web server: loopback only, or all interfaces for other Macs. Data and worker keep running.
    func setSharing(_ enabled: Bool) throws {
        sharing = enabled
        sharingURLs = enabled ? Runtime.lanURLs(port: webPort) : []
        server?.terminate()
        server?.waitUntilExit()
        try startServer()
        try waitForServer()
    }

    func stop() {
        stopping = true
        isRunning = false
        guard hasLocalData else { return }
        worker?.terminate()
        server?.terminate()
        worker?.waitUntilExit()
        server?.waitUntilExit()
        _ = try? run(pg("pg_ctl"), ["-D", pgData.path, "-m", "fast", "-w", "stop"], log: "postgres")
    }

    // MARK: - Steps

    private func prepareDirectories() throws {
        for directory in [support, logs, varDir] {
            try fileManager.createDirectory(at: directory, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        }

        for name in ["postgres", "server", "worker", "console"] {
            let file = logs.appendingPathComponent("\(name).log")
            if let size = try? fileManager.attributesOfItem(atPath: file.path)[.size] as? Int, size > 5_000_000 {
                try? fileManager.removeItem(at: file)
            }
        }
    }

    private func initDatabaseIfNeeded() throws {
        guard !fileManager.fileExists(atPath: pgData.appendingPathComponent("PG_VERSION").path) else {
            return
        }

        try? fileManager.removeItem(at: pgData)
        let passwordFile = support.appendingPathComponent(".pwfile")
        try secrets.databasePassword.write(to: passwordFile, atomically: true, encoding: .utf8)
        try fileManager.setAttributes([.posixPermissions: 0o600], ofItemAtPath: passwordFile.path)
        defer { try? fileManager.removeItem(at: passwordFile) }

        try check(L10n.text("step.createDatabase"), run(pg("initdb"), [
            "-D", pgData.path,
            "-U", "app",
            "--pwfile=\(passwordFile.path)",
            "--auth=scram-sha-256",
            "-E", "UTF8",
            "--no-locale",
        ], log: "postgres"))
    }

    private func startDatabase() throws {
        let pid = pgData.appendingPathComponent("postmaster.pid")
        if fileManager.fileExists(atPath: pid.path) {
            _ = try? run(pg("pg_ctl"), ["-D", pgData.path, "-m", "fast", "-w", "stop"], log: "postgres")
            try? fileManager.removeItem(at: pid)
        }

        let options = "-c listen_addresses=127.0.0.1 -p \(dbPort) -c unix_socket_directories='' -c shared_buffers=32MB"
        try check(L10n.text("step.startDatabase"), run(pg("pg_ctl"), [
            "-D", pgData.path,
            "-l", logs.appendingPathComponent("postgres.log").path,
            "-w", "-t", "60",
            "-o", options,
            "start",
        ], log: "postgres"))
    }

    private func prepareApplication() throws {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "0"
        let marker = varDir.appendingPathComponent("version")
        let installed = try? String(contentsOf: marker, encoding: .utf8)

        if installed != version {
            try? fileManager.removeItem(at: varDir.appendingPathComponent("cache"))
        }

        try check(
            L10n.text("step.database"),
            console(["doctrine:database:create", "--if-not-exists", "--no-interaction"])
        )
        try check(
            L10n.text("step.migrations"),
            console(["doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"])
        )
        try check(L10n.text("step.referenceData"), console(["app:seed", "--no-interaction"]))

        if installed != version {
            try check(L10n.text("step.cache"), console(["cache:warmup", "--no-interaction"]))
            try version.write(to: marker, atomically: true, encoding: .utf8)
        }
    }

    private func startServer() throws {
        let process = makeProcess(php, phpArguments() + [
            "-S", "\(sharing ? "0.0.0.0" : "127.0.0.1"):\(webPort)",
            "-t", appDir.appendingPathComponent("public").path,
            runtimeDir.appendingPathComponent("router.php").path,
        ], log: "server")
        process.environment?["PHP_CLI_SERVER_WORKERS"] = "4"
        try process.run()
        server = process
    }

    private func startWorker() {
        let process = makeProcess(php, phpArguments() + [
            appDir.appendingPathComponent("bin/console").path,
            "messenger:consume", "scheduler_default", "--time-limit=3600", "--no-interaction",
        ], log: "worker")
        process.terminationHandler = { [weak self] _ in
            guard let self, !self.stopping else { return }
            DispatchQueue.global().asyncAfter(deadline: .now() + 5) { self.startWorker() }
        }
        try? process.run()
        worker = process
    }

    private func fetchRatesInBackground() {
        DispatchQueue.global(qos: .utility).async { [weak self] in
            _ = try? self?.console(["app:recurring:run", "--no-interaction"])
            _ = try? self?.console(["app:rates:fetch", "--days=7", "--no-interaction"])
        }
    }

    private func waitForServer() throws {
        let url = baseURL.appendingPathComponent("health")

        for _ in 0..<100 {
            if let server, !server.isRunning {
                throw Failure.step(L10n.text("step.webServer"), L10n.text("error.serverStopped"))
            }

            var request = URLRequest(url: url)
            request.timeoutInterval = 1
            let done = DispatchSemaphore(value: 0)
            var ok = false
            URLSession.shared.dataTask(with: request) { _, response, _ in
                ok = (response as? HTTPURLResponse)?.statusCode == 200
                done.signal()
            }.resume()
            done.wait()

            if ok {
                return
            }

            Thread.sleep(forTimeInterval: 0.15)
        }

        throw Failure.step(L10n.text("step.webServer"), L10n.text("error.serverTimeout"))
    }

    // MARK: - Processes

    private func console(_ arguments: [String]) throws -> (Int32, String) {
        try run(php, phpArguments() + [appDir.appendingPathComponent("bin/console").path] + arguments, log: "console")
    }

    private func phpArguments() -> [String] {
        var arguments = ["-c", runtimeDir.appendingPathComponent("php.ini").path, "-d", "error_log=\(logs.appendingPathComponent("php-errors.log").path)"]
        let certificates = runtimeDir.appendingPathComponent("cert.pem").path
        if fileManager.fileExists(atPath: certificates) {
            arguments += ["-d", "curl.cainfo=\(certificates)", "-d", "openssl.cafile=\(certificates)"]
        }
        return arguments
    }

    private func environment() -> [String: String] {
        let inherited = ProcessInfo.processInfo.environment
        return [
            "PATH": "/usr/bin:/bin:/usr/sbin:/sbin",
            "HOME": inherited["HOME"] ?? NSHomeDirectory(),
            "TMPDIR": inherited["TMPDIR"] ?? NSTemporaryDirectory(),
            "LC_ALL": "en_US.UTF-8",
            "LANG": "en_US.UTF-8",
            "PHP_INI_SCAN_DIR": "",
            "APP_ENV": "prod",
            "APP_DEBUG": "0",
            "APP_SECRET": secrets.appSecret,
            "APP_VAR_DIR": varDir.path,
            "APP_LOG_DIR": logs.path,
            "DATABASE_URL": "postgresql://app:\(secrets.databasePassword)@127.0.0.1:\(dbPort)/app?serverVersion=16&charset=utf8",
            "DEFAULT_URI": "http://127.0.0.1:\(webPort)",
            "TRUSTED_HOSTS": sharing ? Runtime.lanHostsPattern : "^(localhost|127\\.0\\.0\\.1)$",
            "SHARING_URLS": sharingURLs.joined(separator: ","),
            "LOCK_DSN": "flock",
        ]
    }

    private func makeProcess(_ executable: String, _ arguments: [String], log: String) -> Process {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: executable)
        process.arguments = arguments
        process.currentDirectoryURL = appDir
        process.environment = environment()
        let handle = logHandle(log)
        process.standardOutput = handle
        process.standardError = handle
        process.standardInput = FileHandle.nullDevice
        return process
    }

    @discardableResult
    private func run(_ executable: String, _ arguments: [String], log: String) throws -> (Int32, String) {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: executable)
        process.arguments = arguments
        process.currentDirectoryURL = support
        process.environment = environment()
        let pipe = Pipe()
        process.standardOutput = pipe
        process.standardError = pipe
        process.standardInput = FileHandle.nullDevice
        try process.run()
        let data = pipe.fileHandleForReading.readDataToEndOfFile()
        process.waitUntilExit()
        let output = String(decoding: data, as: UTF8.self)
        let command = ([URL(fileURLWithPath: executable).lastPathComponent] + arguments.filter { !$0.hasPrefix("/") })
            .joined(separator: " ")
        logHandle(log).write(Data("$ \(command)\n\(output)\n".utf8))
        return (process.terminationStatus, output)
    }

    private func check(_ step: String, _ result: (Int32, String)) throws {
        guard result.0 == 0 else {
            let tail = result.1.split(separator: "\n").suffix(4).joined(separator: "\n")
            throw Failure.step(step, tail.isEmpty ? L10n.text("error.exitCode", result.0) : tail)
        }
    }

    private func logHandle(_ name: String) -> FileHandle {
        let file = logs.appendingPathComponent("\(name).log")
        if !fileManager.fileExists(atPath: file.path) {
            fileManager.createFile(atPath: file.path, contents: nil, attributes: [.posixPermissions: 0o600])
        }
        let handle = (try? FileHandle(forWritingTo: file)) ?? FileHandle.nullDevice
        handle.seekToEndOfFile()
        return handle
    }

    private func status(_ text: String) {
        DispatchQueue.main.async { self.onStatus?(text) }
    }

    /// Hosts other Macs on the home network can use: the Bonjour name and private IPv4 ranges.
    static let lanHostsPattern =
        "^(localhost|127\\.0\\.0\\.1|[A-Za-z0-9-]+\\.local|192\\.168\\.\\d{1,3}\\.\\d{1,3}|10\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}"
        + "|172\\.(1[6-9]|2\\d|3[01])\\.\\d{1,3}\\.\\d{1,3})$"

    static func lanURLs(port: Int) -> [String] {
        var urls: [String] = []

        if let name = SCDynamicStoreCopyLocalHostName(nil) as String? {
            urls.append("http://\(name).local:\(port)")
        }

        var interfaces: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&interfaces) == 0, let first = interfaces else { return urls }
        defer { freeifaddrs(interfaces) }

        for pointer in sequence(first: first, next: { $0.pointee.ifa_next }) {
            let flags = Int32(pointer.pointee.ifa_flags)
            guard let address = pointer.pointee.ifa_addr, address.pointee.sa_family == UInt8(AF_INET),
                  flags & IFF_UP != 0, flags & IFF_LOOPBACK == 0 else { continue }

            var host = [CChar](repeating: 0, count: Int(NI_MAXHOST))
            if getnameinfo(address, socklen_t(address.pointee.sa_len), &host, socklen_t(host.count), nil, 0, NI_NUMERICHOST) == 0 {
                let ip = String(cString: host)
                if ip.range(of: lanHostsPattern, options: .regularExpression) != nil {
                    urls.append("http://\(ip):\(port)")
                }
            }
        }

        return Array(NSOrderedSet(array: urls)) as? [String] ?? urls
    }

    static func isFree(_ port: Int) -> Bool {
        let socketFD = socket(AF_INET, SOCK_STREAM, 0)
        guard socketFD >= 0 else { return false }
        defer { close(socketFD) }

        // Like "php -S": connections of the previous run in TIME_WAIT must not move the server to another port.
        var reuse: Int32 = 1
        setsockopt(socketFD, SOL_SOCKET, SO_REUSEADDR, &reuse, socklen_t(MemoryLayout<Int32>.size))

        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = UInt16(port).bigEndian
        address.sin_addr.s_addr = INADDR_ANY

        return withUnsafePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(socketFD, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
        } == 0
    }

    static func freePort() throws -> Int {
        let socketFD = socket(AF_INET, SOCK_STREAM, 0)
        guard socketFD >= 0 else { throw Failure.step(L10n.text("step.port"), L10n.text("error.noSocket")) }
        defer { close(socketFD) }

        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = 0
        address.sin_addr.s_addr = inet_addr("127.0.0.1")

        let bound = withUnsafePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(socketFD, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
        }
        guard bound == 0 else { throw Failure.step(L10n.text("step.port"), L10n.text("error.portBind")) }

        var length = socklen_t(MemoryLayout<sockaddr_in>.size)
        let named = withUnsafeMutablePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { getsockname(socketFD, $0, &length) }
        }
        guard named == 0 else { throw Failure.step(L10n.text("step.port"), L10n.text("error.portLookup")) }

        return Int(UInt16(bigEndian: address.sin_port))
    }
}

/// Random credentials generated on first launch and kept in a 0600 file next to the data.
struct Secrets: Codable {
    let databasePassword: String
    let appSecret: String

    static func load(from file: URL) throws -> Secrets {
        if let data = try? Data(contentsOf: file), let secrets = try? JSONDecoder().decode(Secrets.self, from: data) {
            return secrets
        }

        let secrets = Secrets(databasePassword: random(32), appSecret: random(32))
        try JSONEncoder().encode(secrets).write(to: file, options: .atomic)
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: file.path)
        return secrets
    }

    private static func random(_ bytes: Int) -> String {
        var buffer = [UInt8](repeating: 0, count: bytes)
        guard SecRandomCopyBytes(kSecRandomDefault, bytes, &buffer) == errSecSuccess else {
            fatalError("No secure random source")
        }
        return buffer.map { String(format: "%02x", $0) }.joined()
    }
}
