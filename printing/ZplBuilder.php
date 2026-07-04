<?php
namespace AQNEX\Printing;

class ZplBuilder {
    private $commands = [];

    public function __construct() {
        $this->start();
    }

    public function start() {
        $this->commands = ["^XA"]; // Start format
        return $this;
    }

    public function end() {
        $this->commands[] = "^XZ"; // End format
        return $this;
    }

    public function setLabelWidth(int $widthDots) {
        $this->commands[] = "^PW" . $widthDots;
        return $this;
    }

    public function setLabelHeight(int $heightDots) {
        $this->commands[] = "^LL" . $heightDots;
        return $this;
    }

    public function text(int $x, int $y, string $text, int $fontSize = 24, string $fontName = "0", string $orientation = "N") {
        // ZPL text drawing
        // ^FOx,y: Field Origin
        // ^A[font],[orientation],[height],[width]
        // ^FD[data]^FS: Field Data
        $this->commands[] = sprintf("^FO%d,%d^A%s%s,%d,%d^FD%s^FS", $x, $y, $fontName, $orientation, $fontSize, $fontSize, $text);
        return $this;
    }

    public function barcodeEan13(int $x, int $y, string $data, int $height = 60, string $printInterpretationLine = "Y") {
        // ^BE: EAN-13 barcode
        // ^FD: Field Data
        $this->commands[] = sprintf("^FO%d,%d^BE%s,%d,%s^FD%s^FS", $x, $y, "N", $height, $printInterpretationLine, $data);
        return $this;
    }

    public function barcodeCode128(int $x, int $y, string $data, int $height = 60, string $printInterpretationLine = "Y") {
        // ^BC: Code 128 barcode
        $this->commands[] = sprintf("^FO%d,%d^BC%s,%d,%s,N,N^FD%s^FS", $x, $y, "N", $height, $printInterpretationLine, $data);
        return $this;
    }

    public function getPayload(): string {
        $this->end();
        return implode("\n", $this->commands);
    }
}
