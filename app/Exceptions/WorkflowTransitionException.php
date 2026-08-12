<?php

namespace App\Exceptions;

use Exception;

// Dilempar oleh WorkflowStatusService kalau perpindahan status ditolak
// (transisi tidak valid, guard gagal, atau payload wajib belum lengkap).
// Pesannya aman ditampilkan langsung ke user (toast / form error).
class WorkflowTransitionException extends Exception
{
}
