<?php
namespace AQNEX\Printing;

class EscPosBuilder {
    private $buffer = "";

    public function __construct() {
        $this->initialize();
    }

    public function initialize() {
        $this->buffer = "\x1b\x40"; // ESC @ - Initialize printer
        return $this;
    }

    public function alignCenter() {
        $this->buffer .= "\x1b\x61\x01"; // ESC a 1 - Center align
        return $this;
    }

    public function alignLeft() {
        $this->buffer .= "\x1b\x61\x00"; // ESC a 0 - Left align
        return $this;
    }

    public function alignRight() {
        $this->buffer .= "\x1b\x61\x02"; // ESC a 2 - Right align
        return $this;
    }

    public function setBold(bool $on = true) {
        $this->buffer .= $on ? "\x1b\x45\x01" : "\x1b\x45\x00"; // ESC E - Bold
        return $this;
    }

    public function setFontSize(int $width = 1, int $height = 1) {
        // Limit sizes to range 1-8
        $w = max(1, min(8, $width)) - 1;
        $h = max(1, min(8, $height)) - 1;
        $n = ($w << 4) | $h;
        $this->buffer .= "\x1d\x21" . chr($n); // GS ! n - Set font size
        return $this;
    }

    public function text(string $text) {
        // Convert UTF-8 to Windows-1256 for Arabic printing or pass as-is
        // Note: For Arabic thermal printers, standard encoding is CP1256 (Windows-1256).
        // Let's use iconv if available to convert encoding for the printer.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'windows-1256//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $this->buffer .= $text;
        return $this;
    }

    public function line(string $text = "") {
        $this->text($text . "\n");
        return $this;
    }

    public function feed(int $lines = 1) {
        $this->buffer .= "\x1b\x64" . chr($lines); // ESC d n - Feed n lines
        return $this;
    }

    public function cut() {
        $this->buffer .= "\x1d\x56\x41\x03"; // GS V 65 3 - Feed paper and cut
        return $this;
    }

    public function qrCode(string $data) {
        $len = strlen($data);
        $pL = ($len + 3) % 256;
        $pH = intval(($len + 3) / 256);

        // 1. Set cell size (pixel size) to 6
        $this->buffer .= "\x1d\x28\x6b\x03\x00\x31\x43\x06";

        // 2. Set error correction level L (7%)
        $this->buffer .= "\x1d\x28\x6b\x03\x00\x31\x45\x30";

        // 3. Store data in symbol storage area
        $this->buffer .= "\x1d\x28\x6b" . chr($pL) . chr($pH) . "\x31\x50\x30" . $data;

        // 4. Print the symbol data
        $this->buffer .= "\x1d\x28\x6b\x03\x00\x31\x51\x30";
        
        return $this;
    }

    public function getBuffer(): string {
        return $this->buffer;
    }
}
