<?php

/*
 * decodificadorGIF
 *  
 * @author     Carlos Daniel Peña Dutary <cdutary@grupocti.com>
 * @version    1.5  - Forked from https://github.com/Sybio/GifFrameExtractor
 */

class decodificadorGIF {

    use extras;

    private $framesOriginales, $gifApplicationData = [], $gifCommentData = [], $gifVersion, $anchoCanvasGIF, $altoCanvasGIF, $gifBackgroundColor, $gifPixelAspectRatio, $gifPackedFields, $gifGlobalColorTable, $archivoGIF, $puntero, $iFrame, $frames;

    public function extraerGIF($archivo, $framesOriginales = false) {
        $this->framesOriginales = $framesOriginales;
        $this->reiniciarElObjeto();
        $this->analizarGIF($archivo);
        $this->ensamblarNuevosFrames();
        return $this->frames;
    }

    private function analizarGIF($archivo) {
        $this->esAnimadoGIF($archivo);
        $this->analizarHeaderGIF();
        $this->analizarLogicalScreenDescriptorGIF();
        $this->analizarGlobalColorTableGIF();
        while (($byteActual = $this->leerBytesPartiendoDelPuntero(1)) && $byteActual !== "\x3b" && !$this->esFinaldelArchivo()) {
            $this->obtenerFramesGIF($byteActual);
        }
    }

    private function ensamblarNuevosFrames() {
        for ($i = 0; $i < count($this->recuadros); $i++) {
            $img = imagecreatefromstring("GIF" . $this->gifVersion . $this->gifAnchoCanvas . $this->gifAltoCanvas . $this->gifPackedFields . $this->gifBackgroundColor . $this->gifPixelAspectRatio . $this->gifGlobalColorTable . $this->recuadros[$i]["graphicsextension"] . $this->recuadros[$i]["imagedata"] . "\x3b");
            $this->framesOriginales ? imagepalettetotruecolor($img) : $img = $this->crearFrameEncimaDelAnterior($i, $img);
            $this->frames[$i]['image'] = $img;
        }
    }

    private function crearFrameEncimaDelAnterior($i, $frameOriginal) {

        $imagenAnterior = $i > 0 ? $this->frames[$i - 1]['image'] : $frameOriginal;
        $imagenNueva = imagecreatetruecolor($this->gifAnchoCanvas, $this->gifAltoCanvas);
        imagecolortransparent($imagenNueva, imagecolorallocatealpha($imagenNueva, 0, 0, 0, 127));
        imagesavealpha($imagenNueva, true);

        $transparencia = imagecolortransparent($frameOriginal);

        if ($transparencia > -1 && imagecolorstotal($frameOriginal) > $transparencia) {
            $transparenciaActual = imagecolorsforindex($frameOriginal, $transparencia);
            imagecolortransparent($imagenNueva, imagecolorallocate($imagenNueva, $transparenciaActual['red'], $transparenciaActual['green'], $transparenciaActual['blue']));
        }
        if ((int) $this->frames[$i]['disposalMethod'] == 1 && $i > 0) {
            imagecopy($imagenNueva, $imagenAnterior, 0, 0, 0, 0, $this->gifAnchoCanvas, $this->gifAltoCanvas);
        }
        imagecopyresampled($imagenNueva, $frameOriginal, $this->frames[$i]["x"], $this->frames[$i]["y"], 0, 0, $this->frames[$i]["ancho"], $this->frames[$i]["alto"], $this->gifAnchoCanvas, $this->gifAltoCanvas);
        $this->frames[$i]['x'] = 0;
        $this->frames[$i]['y'] = 0;
        $this->frames[$i]['ancho'] = $this->gifAnchoCanvas;
        $this->frames[$i]['alto'] = $this->gifAltoCanvas;
        return $imagenNueva;
    }

    private function esAnimadoGIF($archivo) {
        ($this->archivoGIF = fopen($archivo, 'rb')) ?: $this->error('Error al abrir el archivo: ' . error_get_last()['message']);
        $headerAnimado = 0;
        while (!feof($this->archivoGIF) && $headerAnimado < 2) {
            $headerAnimado += preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', fread($this->archivoGIF, 1024 * 100));
        }
        $headerAnimado > 1 ? (rewind($this->archivoGIF) ?: $this->error('Esto no es un archivo')) : $this->error('Este archivo NO es animado');
    }

    private function analizarHeaderGIF() {
        $this->leerBytesPartiendoDelPuntero(3) === "GIF" ?: $this->error('Este archivo no tiene header GIF');
        $this->gifVersion = $this->leerBytesPartiendoDelPuntero(3);
        $this->gifVersion === "\x38\x39\x61" || $this->gifVersion === "\x38\x37\x61" ?: $this->error("Este GIF no parece contener un campo de versión válido: " . $this->gifVersion);
    }

    private function analizarLogicalScreenDescriptorGIF() {
        $this->gifAnchoCanvas = $this->leerBytesPartiendoDelPuntero(2);
        $this->gifAltoCanvas = $this->leerBytesPartiendoDelPuntero(2);
        $this->anchoCanvasGIF = $this->obtenerIntDeDosBytes($this->gifAnchoCanvas);
        $this->altoCanvasGIF = $this->obtenerIntDeDosBytes($this->gifAltoCanvas);
        $this->analizarPackedFieldsGIF();
        $this->gifBackgroundColor = $this->leerBytesPartiendoDelPuntero(1);
        $this->gifPixelAspectRatio = $this->leerBytesPartiendoDelPuntero(1);
    }

    private function analizarPackedFieldsGIF() {
        $this->gifPackedFields = $this->leerBytesPartiendoDelPuntero(1);
        $this->gifGlobalColorTableFlag = $this->ObtenerNbitsDeEsteByte($this->gifPackedFields, 0, 1);
        $this->gifSizeOfGlobalColorTable = $this->ObtenerNbitsDeEsteByte($this->gifPackedFields, 5, 3);
    }

    private function analizarGlobalColorTableGIF() {
        $this->gifGlobalColorTable = ($this->gifGlobalColorTableFlag == 1 ? $this->leerBytesPartiendoDelPuntero(pow(2, $this->gifSizeOfGlobalColorTable + 1) * 3) : '');
    }

    private function obtenerFramesGIF($tipoDeBloque) {
        if ("\x21" == $tipoDeBloque) {
            $this->debug('----Extension----');
            $this->procesarExtensionGIF();
        } elseif ($tipoDeBloque == "\x2c") {
            $this->analizarImageDescriptor();
            $this->debug('---Bloque de imagen---' . PHP_EOL . print_r($this->recuadros[$this->iFrame - 1], true) . '------------------------------------------');
        } else {
            $this->error('Encontramos un bloque que no corresponde con el standard GIF');
        }
    }

    private function procesarExtensionGIF() {
        $extension = $this->leerBytesPartiendoDelPuntero(1);

        if ($extension == "\xFF") {

            $this->analizarApplicationDataGIF();
            $this->debug('--Application Data Extension--' . PHP_EOL . ' Datos :' . print_r($this->gifApplicationData, true));
        } elseif ($extension == "\xfe") {

            $this->analizarCommentDataGIF();
            $this->debug('--Comment Extension--' . PHP_EOL . ' Datos :' . print_r($this->gifCommentData, true));
        } elseif ($extension == "\xf9") {

            $this->analizarGraphicControlExtensionGIF();
            $this->debug('--Graphic Control Extension--' . PHP_EOL . ' Datos :' . print_r($this->recuadros[$this->iFrame], true));
        } else {
            $this->error('Encontramos una extension que no corresponde con el standard GIF || Puntero: ' . $this->puntero);
        }
    }

    private function analizarApplicationDataGIF() {
        $i = count($this->gifApplicationData) + 1;
        $this->gifApplicationData[$i]['tamanio'] = $this->leerByteComoIntPartiendoDelCursor();
        $this->gifApplicationData[$i]['identificador'] = $this->leerBytesPartiendoDelPuntero(8);
        $this->gifApplicationData[$i]['authCode'] = $this->leerBytesPartiendoDelPuntero(3);
        $this->gifApplicationData[$i]['data'] = $this->leerBloqueData($this->leerByteComoIntPartiendoDelCursor());
    }

    private function analizarCommentDataGIF() {
        $start = count($this->gifCommentData) + 1;
        $this->gifCommentData[$start]['data'] = $this->leerBloqueData($this->leerByteComoIntPartiendoDelCursor());
    }

    private function analizarGraphicControlExtensionGIF() {
        $blockSize = $this->leerBytesPartiendoDelPuntero(1);
        $packedFields = $this->leerBytesPartiendoDelPuntero(1);
        $this->frames[$this->iFrame]['disposalMethod'] = $this->ObtenerNbitsDeEsteByte($packedFields, 3, 3);
        $this->recuadros[$this->iFrame]['userInputFlag'] = $this->ObtenerNbitsDeEsteByte($packedFields, 6, 1);
        $this->recuadros[$this->iFrame]['transparentColorFlag'] = $this->ObtenerNbitsDeEsteByte($packedFields, 7, 1);
        $this->recuadros[$this->iFrame]['delayTime'] = $this->leerBytesPartiendoDelPuntero(2);
        $this->frames[$this->iFrame]['duration'] = $this->obtenerIntDeDosBytes($this->recuadros[$this->iFrame]['delayTime']);
        $this->recuadros[$this->iFrame]['transparentColorIndex'] = $this->leerBytesPartiendoDelPuntero(1);
        $this->recuadros[$this->iFrame]['blockTerminator'] = $this->leerBytesPartiendoDelPuntero(1);

        $this->recuadros[$this->iFrame]["graphicsextension"] = "\x21\xf9" . $blockSize . $packedFields . $this->recuadros[$this->iFrame]['delayTime'] . $this->recuadros[$this->iFrame]['transparentColorIndex'] . $this->recuadros[$this->iFrame]['blockTerminator'];
    }

    private function analizarImageDescriptor() {

        $this->obtenerDimensionesDeFrame();
        $packedFields = $this->leerBytesPartiendoDelPuntero(1);
        $this->recuadros[$this->iFrame]["localColorTableFlag"] = $this->ObtenerNbitsDeEsteByte($packedFields, 0, 1);
        $this->recuadros[$this->iFrame]["InterlaceFlag"] = $this->ObtenerNbitsDeEsteByte($packedFields, 1, 1);
        $this->recuadros[$this->iFrame]["SortFlag"] = $this->ObtenerNbitsDeEsteByte($packedFields, 2, 1);
        $this->recuadros[$this->iFrame]["localColorTableSize"] = $this->ObtenerNbitsDeEsteByte($packedFields, 5, 3);
        $size = pow(2, ord($this->recuadros[$this->iFrame]["localColorTableSize"]) + 1) * 3;

        if ($this->recuadros[$this->iFrame]["localColorTableFlag"] == 1) {
            $this->recuadros[$this->iFrame]["localColorTable"] = $this->leerBytesPartiendoDelPuntero(pow(2, $size + 1) * 3);
        }

        $tail_maybe = $this->leerBytesPartiendoDelPuntero(1);
        $tamanioDelBloque = $this->leerBytesPartiendoDelPuntero(1);
        $dataStream = $this->leerBloqueData(ord($tamanioDelBloque));
        $this->recuadros[$this->iFrame]["imagedata"] = "\x2c" . $this->recuadros[$this->iFrame]["izquierda"] . $this->recuadros[$this->iFrame]["arriba"] . $this->recuadros[$this->iFrame]["ancho"] . $this->recuadros[$this->iFrame]["alto"] . $packedFields . $tail_maybe . $tamanioDelBloque . $dataStream;

        $this->iFrame++;
    }

    private function obtenerDimensionesDeFrame() {
        $this->recuadros[$this->iFrame]["izquierda"] = $this->leerBytesPartiendoDelPuntero(2);
        $this->recuadros[$this->iFrame]["arriba"] = $this->leerBytesPartiendoDelPuntero(2);
        $this->recuadros[$this->iFrame]["ancho"] = $this->leerBytesPartiendoDelPuntero(2);
        $this->recuadros[$this->iFrame]["alto"] = $this->leerBytesPartiendoDelPuntero(2);
        $this->frames[$this->iFrame]["x"] = $this->obtenerIntDeDosBytes($this->recuadros[$this->iFrame]["izquierda"]);
        $this->frames[$this->iFrame]["y"] = $this->obtenerIntDeDosBytes($this->recuadros[$this->iFrame]["arriba"]);
        $this->frames[$this->iFrame]["ancho"] = $this->obtenerIntDeDosBytes($this->recuadros[$this->iFrame]["ancho"]);
        $this->frames[$this->iFrame]["alto"] = $this->obtenerIntDeDosBytes($this->recuadros[$this->iFrame]["alto"]);
    }

    private function obtenerIntDeDosBytes($s) {
        return ord($s[1]) * 256 + ord($s[0]);
    }

    private function leerBloqueData($inicio) {
        $data = $this->leerBytesPartiendoDelPuntero($inicio);
        $bloqueDeLongitud = $this->leerBytesPartiendoDelPuntero(1);
        $longitud = ord($bloqueDeLongitud);
        $data .= $bloqueDeLongitud;

        if ($longitud != 0) {
            while ($longitud != 0) {
                $data .= $this->leerBytesPartiendoDelPuntero($longitud);
                $bloqueDeLongitud = $this->leerBytesPartiendoDelPuntero(1);
                $data .= $bloqueDeLongitud;
                $longitud = ord($bloqueDeLongitud);
            }
        }
        return $data;
    }

    private function leerBytesPartiendoDelPuntero($byteCount) {
        $data = fread($this->archivoGIF, $byteCount);
        $this->puntero += $byteCount;

        return $data;
    }

    private function leerByteComoIntPartiendoDelCursor() {
        $data = fread($this->archivoGIF, 1);
        $this->puntero++;
        return ord($data);
    }

    private function ObtenerNbitsDeEsteByte($byte, $start, $length) {
        $bin = str_pad(decbin(ord($byte)), 8, "0", STR_PAD_LEFT);
        $data = substr($bin, $start, $length);
        return bindec($data);
    }

    private function adelantarPuntero($posiciones) {
        $this->puntero += $posiciones;
        fseek($this->archivoGIF, $this->puntero);
    }

    private function esFinaldelArchivo() {
        if (fgetc($this->archivoGIF) === false) {
            return true;
        }
        fseek($this->archivoGIF, $this->puntero);
        return false;
    }

    private function reiniciarElObjeto() {
        $this->gifHeight = $this->gifWidth = $this->totalDuration = $this->archivoGIF = $this->puntero = $this->iFrame = 0;
        $this->frameDurations = $this->globaldata = $this->orgvars = $this->frames = $this->fileHeader = $this->frameSources = [];
    }

}
