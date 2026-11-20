<?php

namespace App\Support\Security;

use RuntimeException;

/** L'antivirus n'a pas pu analyser le fichier (injoignable, délai dépassé, réponse inattendue). */
class ScannerUnavailable extends RuntimeException {}
