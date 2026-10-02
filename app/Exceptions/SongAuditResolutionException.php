<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 点検結果を適用できないとき（利用者に見せるメッセージを持つ）
 */
class SongAuditResolutionException extends RuntimeException {}
