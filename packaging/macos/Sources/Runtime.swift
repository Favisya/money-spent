import Foundation

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
    private var dbPort: Int = 0

    var onStatus: ((String) -> Void)?

    init() {
        resources = Bundle.main.resourceURL!
        let base = fileManager.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        support = base.appendingPathComponent("MoneySpent", isDirectory: true)
        logs = support.appendingPathComponent("logs", isDirectory: true)
    }

    var dataDirectory: URL { support }
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

    func start() throws {
        try prepareDirectories()
        secrets = try Secrets.load(from: support.appendingPathComponent("secrets.json"))
        dbPort = try Runtime.freePort()
        webPort = try Runtime.freePort()

        status("Готовлю базу данных…")
        try initDatabaseIfNeeded()
        try startDatabase()

        status("Обновляю приложение…")
        try prepareApplication()

        status("Запускаю…")
        try startServer()
        startWorker()
        fetchRatesInBackground()
        try waitForServer()
    }

    func stop() {
        stopping = true
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

        try check("Создание базы", run(pg("initdb"), [
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
        try check("Запуск базы", run(pg("pg_ctl"), [
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

        try check("База", console(["doctrine:database:create", "--if-not-exists", "--no-interaction"]))
        try check("Миграции", console(["doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"]))
        try check("Справочники", console(["app:seed", "--no-interaction"]))

        if installed != version {
            try check("Кеш", console(["cache:warmup", "--no-interaction"]))
            try version.write(to: marker, atomically: true, encoding: .utf8)
        }
    }

    private func startServer() throws {
        let process = makeProcess(php, phpArguments() + [
            "-S", "127.0.0.1:\(webPort)",
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
                throw Failure.step("Веб-сервер", "остановился при запуске, подробности в logs/server.log")
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

        throw Failure.step("Веб-сервер", "не ответил за 15 секунд, подробности в logs/server.log")
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
            "TRUSTED_HOSTS": "^(localhost|127\\.0\\.0\\.1)$",
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
            throw Failure.step(step, tail.isEmpty ? "код \(result.0)" : tail)
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

    static func freePort() throws -> Int {
        let socketFD = socket(AF_INET, SOCK_STREAM, 0)
        guard socketFD >= 0 else { throw Failure.step("Порт", "нет сокета") }
        defer { close(socketFD) }

        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = 0
        address.sin_addr.s_addr = inet_addr("127.0.0.1")

        let bound = withUnsafePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { bind(socketFD, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
        }
        guard bound == 0 else { throw Failure.step("Порт", "не удалось занять порт") }

        var length = socklen_t(MemoryLayout<sockaddr_in>.size)
        let named = withUnsafeMutablePointer(to: &address) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { getsockname(socketFD, $0, &length) }
        }
        guard named == 0 else { throw Failure.step("Порт", "не удалось узнать порт") }

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
