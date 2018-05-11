<?php
class compiladorWebp {

    use extras;

    private $canvasWidth, $canvasAlto,
            $fileWEBP,
            $fileHeader,
            $fileContents,
            $archivo;

    public function convertir($gifArray, $archivo) {

        $this->canvasWidth = $gifArray[0]['ancho'];
        $this->canvasAlto = $gifArray[0]['alto'];
        $this->archivo = pathinfo($archivo);


        // create new WEBP
        $this->fileWEBP = $this->fileHeader = $this->fileContents = "";

        $this->ExtendedFileFormatChunk();
        $this->ANIMchunk();

        foreach ($gifArray as $gifFrame) {
            $frame = $this->getFrameData($gifFrame);

            // Chunk HEADER ANMF
            $this->fileContents .= "ANMF";
            $frameDataChunkSize = $this->bytesToString($this->toUint32(strlen($frame['frameData']) + 16));

            // frame origin X Y
            $fOrigin = $this->bytesToString($this->toUint24($frame['x'] / 2)) . $this->bytesToString($this->toUint24($frame['y'] / 2));

            // frame size (uint24) width-1 , (uint24) height-1
            $fSize = $frame['width'] . $frame['height'];

            // frame duration in miliseconds (uint24)
            $fDuration = $frame['duration'];

            // frame options bits
            // reserved (6 bits) + alpha blending (1 bit) + descartar frame (1 bit)

            $fFlagsBin = "000000" . $frame["blending"] . 0;
            $fFlags = $this->bytesToString($this->binaryToBytes($fFlagsBin));

            // chunk payload
            $this->fileContents .= $frameDataChunkSize . $fOrigin . $fSize . $fDuration . $fFlags . $frame['frameData'];
        }

        $this->crearHeader();
        $this->salvarEnDiscoDuro();
    }

    private function ExtendedFileFormatChunk() {
        // Chunk HEADER VP8X
        $this->fileContents .= "VP8X";
        $headChunkSize = $this->bytesToString($this->toUint32(10));

        // bit flags Rsv|I|L|E|X|A|R|                   Reserved
        $oVP8XflagsBin = "00111110 00000000 00000000 00000000";
        $oVP8Xflags = $this->bytesToString($this->binaryToBytes($oVP8XflagsBin));
        $oCanvasSize = $this->bytesToString($this->toUint24($this->canvasWidth - 1)) . $this->bytesToString($this->toUint24($this->canvasAlto - 1));
        $this->fileContents .= $headChunkSize . $oVP8Xflags . $oCanvasSize;
    }

    private function ANIMchunk() {
        // Chunk HEADER ANIM
        $this->fileContents .= "ANIM";
        $animChunkSize = $this->bytesToString($this->toUint32(6));

        // loop count 16bits, 0 = infinito
        // $this->bytesToString(toUint16(0));
        $oLoopCount = str_repeat(chr(0), 2);

        // 32bits BGRA, Blue Green Red Alpha (0,0,0,0)
        $oBackGround = str_repeat(chr(0), 3) . chr(0);
        $this->fileContents .= $animChunkSize . $oBackGround . $oLoopCount;
    }

    private function getFrameData($frame) {

        ob_start();
        imagewebp($frame['image'], NULL, 100);
        if (ob_get_length() % 2 == 1) :
            echo "\0";
        endif;
        $image_data = ob_get_contents();
        ob_end_clean();

        $posicionDeAlpha = strpos($image_data, "ALPH");

        if ($posicionDeAlpha) {
            $frameData = substr($image_data, $posicionDeAlpha);
            $disposal = 1;
            $alphaBlending = 0;
        } else {
            $frameData = substr($image_data, strpos($image_data, "VP8 "));
            $disposal = 0;
            $alphaBlending = 1;
        }

        return [
            "blending" => $alphaBlending,
            "disposal" => $disposal,
            "frameData" => $frameData,
            "duration" => $this->bytesToString($this->toUint24($frame['duration'])),
            "width" => $this->bytesToString($this->toUint24($frame['ancho'] - 1)),
            "height" => $this->bytesToString($this->toUint24($frame['alto'] - 1)),
            "x" => $frame['x'],
            "y" => $frame['y']
        ];
    }

    private function toUint24($n) {
        $ar = unpack("C*", pack("L", $n));
        array_pop($ar);
        return $ar;
    }

    private function toUint16($n) {
        return unpack("C*", pack("S", $n));
    }

    private function binaryToBytes($bits) {
        $octets = explode(' ', $bits);
        return array_map("bindec", $octets);
    }

    private function crearHeader() {
        // calculate Size and build file header
        $fileSize = $this->bytesToString($this->toUint32(strlen($this->fileContents) + 4));
        $this->fileHeader = "RIFF" . $fileSize . "WEBP";
    }

    private function toUint32($n) {
        return unpack("C*", pack("L", $n));
    }

    private function bytesToString($bytes) {
        return implode(array_map("chr", $bytes));
    }

    private function salvarEnDiscoDuro() {
        file_put_contents($this->archivo['filename'] . '.webp', $this->fileHeader . $this->fileContents);
    }

}
