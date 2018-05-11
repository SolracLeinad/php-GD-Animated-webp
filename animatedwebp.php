<?php

trait extras {

    protected $debug = false;

    private function debug($mensaje) {
        echo!$this->debug ? '' : '<pre>' . $mensaje . '</pre>';
    }

    private function error($error) {
        throw new Exception($error);
    }

}

include_once 'decodificadorGIF.php';
include_once 'compiladorWebp.php';

class animatedwebp {

    use extras;

    public function convertirGIFaWEBP($archivo) {
        try {
            $frames = ((new decodificadorGIF)->extraerGIF($archivo, true));
            (new compiladorWebp)->convertir($frames, $archivo);
        } catch (Exception $exc) {
            print_r($exc);
            exit();
        }
    }
}

/* You can use it like this:

 (new animatedwebp)->convertirGIFaWEBP('PATH TO GIF');
 * 
 */
 