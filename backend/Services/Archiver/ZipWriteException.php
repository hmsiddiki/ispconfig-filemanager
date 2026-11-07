<?php

namespace Filegator\Services\Archiver;

/** The zip file itself cannot be written (usually a full disk): no later entry would fare better, so the whole job stops. */
class ZipWriteException extends \RuntimeException {}
