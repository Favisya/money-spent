import AppKit

// Draws the app icon (the "debt line": a rail with a centre mark and a filled side) into an .iconset folder.
let output = CommandLine.arguments.count > 1 ? CommandLine.arguments[1] : "AppIcon.iconset"
try? FileManager.default.createDirectory(atPath: output, withIntermediateDirectories: true)

func render(_ size: Int) -> Data {
    let s = CGFloat(size)
    let rep = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: size, pixelsHigh: size, bitsPerSample: 8,
                               samplesPerPixel: 4, hasAlpha: true, isPlanar: false, colorSpaceName: .deviceRGB,
                               bytesPerRow: 0, bitsPerPixel: 0)!
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = NSGraphicsContext(bitmapImageRep: rep)

    let inset = s * 0.09
    let tile = NSRect(x: inset, y: inset, width: s - 2 * inset, height: s - 2 * inset)
    let shape = NSBezierPath(roundedRect: tile, xRadius: tile.width * 0.225, yRadius: tile.width * 0.225)
    NSGradient(starting: NSColor(srgbRed: 0.07, green: 0.53, blue: 0.48, alpha: 1),
               ending: NSColor(srgbRed: 0.03, green: 0.36, blue: 0.33, alpha: 1))!.draw(in: shape, angle: -90)

    let railHeight = tile.height * 0.085
    let railY = tile.midY - railHeight / 2
    let left = tile.minX + tile.width * 0.16
    let right = tile.maxX - tile.width * 0.16
    NSColor(white: 1, alpha: 0.28).setFill()
    NSBezierPath(roundedRect: NSRect(x: left, y: railY, width: right - left, height: railHeight),
                 xRadius: railHeight / 2, yRadius: railHeight / 2).fill()

    NSColor.white.setFill()
    let fillEnd = tile.midX + (right - tile.midX) * 0.72
    NSBezierPath(roundedRect: NSRect(x: tile.midX, y: railY, width: fillEnd - tile.midX, height: railHeight),
                 xRadius: railHeight / 2, yRadius: railHeight / 2).fill()

    let tickWidth = tile.width * 0.03
    let tickHeight = tile.height * 0.26
    NSBezierPath(roundedRect: NSRect(x: tile.midX - tickWidth / 2, y: tile.midY - tickHeight / 2,
                                     width: tickWidth, height: tickHeight),
                 xRadius: tickWidth / 2, yRadius: tickWidth / 2).fill()

    let dot = tile.width * 0.1
    NSBezierPath(ovalIn: NSRect(x: fillEnd - dot / 2, y: tile.midY - dot / 2, width: dot, height: dot)).fill()

    NSGraphicsContext.restoreGraphicsState()
    return rep.representation(using: .png, properties: [:])!
}

for base in [16, 32, 128, 256, 512] {
    try! render(base).write(to: URL(fileURLWithPath: "\(output)/icon_\(base)x\(base).png"))
    try! render(base * 2).write(to: URL(fileURLWithPath: "\(output)/icon_\(base)x\(base)@2x.png"))
}
