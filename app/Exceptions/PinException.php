<?php

namespace App\Exceptions;

use Exception;

// Dilempar oleh PinService kalau aksi pin ditolak (sudah kena batas
// maksimal, atau kontennya sudah selesai/dibatalkan sehingga nggak relevan
// lagi buat di-pin). Pesannya aman ditampilkan langsung ke user (lihat
// components/pin-button).
class PinException extends Exception
{
}
