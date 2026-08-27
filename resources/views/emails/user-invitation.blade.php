<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Undangan Bergabung ke 523 Studio</title>
</head>

<body style="
    margin: 0;
    padding: 0;
    background-color: #f7f8fc;
    font-family: Arial, Helvetica, sans-serif;
    color: #14181a;
">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="width:100%; background-color:#f7f8fc;">
    <tr>
        <td align="center" style="padding:40px 16px;">

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                   style="
                        width:100%;
                        max-width:600px;
                        background:#ffffff;
                        border:1px solid #eef0f4;
                        border-radius:16px;
                        overflow:hidden;
                   ">

                {{-- Header --}}
                <tr>
                    <td style="
                        background-color:#044b46;
                        padding:28px 36px;
                    ">
                        <img
                            src="{{ $message->embed(public_path('images/logo.png')) }}"
                            alt="523 Studio"
                            style="
                                display:block;
                                height:36px;
                                width:auto;
                                max-width:180px;
                            "
                        >
                    </td>
                </tr>

                {{-- Content --}}
                <tr>
                    <td style="padding:36px;">

                        <p style="
                            margin:0 0 8px;
                            font-size:14px;
                            line-height:1.6;
                            color:#5c6266;
                        ">
                            Undangan Tim
                        </p>

                        <h1 style="
                            margin:0 0 20px;
                            font-size:26px;
                            line-height:1.3;
                            font-weight:700;
                            color:#14181a;
                        ">
                            Selamat datang di 523 Studio, {{ $invitedUser->name }}.
                        </h1>

                        <p style="
                            margin:0 0 24px;
                            font-size:15px;
                            line-height:1.7;
                            color:#5c6266;
                        ">
                            Anda telah diundang untuk bergabung ke
                            <strong style="color:#14181a;">523 Studio Platform</strong>
                            sebagai
                            <strong style="color:#044b46;">
                                {{ $invitedUser->roleNamesLabel() }}
                            </strong>.
                        </p>

                        {{-- Account information --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                               style="
                                    margin:0 0 28px;
                                    background:#f0f5f4;
                                    border:1px solid #dbe6e4;
                                    border-radius:12px;
                               ">
                            <tr>
                                <td style="padding:18px 20px;">
                                    <p style="
                                        margin:0 0 5px;
                                        font-size:12px;
                                        font-weight:700;
                                        text-transform:uppercase;
                                        letter-spacing:.5px;
                                        color:#5c6266;
                                    ">
                                        Email yang didaftarkan
                                    </p>

                                    <p style="
                                        margin:0;
                                        font-size:15px;
                                        font-weight:600;
                                        color:#044b46;
                                        word-break:break-word;
                                    ">
                                        {{ $invitedUser->email }}
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <h2 style="
                            margin:0 0 14px;
                            font-size:17px;
                            font-weight:700;
                            color:#14181a;
                        ">
                            Cara masuk
                        </h2>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                               style="margin-bottom:28px;">
                            <tr>
                                <td valign="top" width="32" style="padding:0 0 14px;">
                                    <div style="
                                        width:24px;
                                        height:24px;
                                        line-height:24px;
                                        text-align:center;
                                        border-radius:50%;
                                        background:#f0f5f4;
                                        color:#044b46;
                                        font-size:12px;
                                        font-weight:700;
                                    ">1</div>
                                </td>
                                <td style="
                                    padding:1px 0 14px 10px;
                                    font-size:14px;
                                    line-height:1.6;
                                    color:#5c6266;
                                ">
                                    Klik tombol <strong style="color:#14181a;">Masuk ke 523 Studio</strong>
                                    di bawah.
                                </td>
                            </tr>

                            <tr>
                                <td valign="top" width="32" style="padding:0 0 14px;">
                                    <div style="
                                        width:24px;
                                        height:24px;
                                        line-height:24px;
                                        text-align:center;
                                        border-radius:50%;
                                        background:#f0f5f4;
                                        color:#044b46;
                                        font-size:12px;
                                        font-weight:700;
                                    ">2</div>
                                </td>
                                <td style="
                                    padding:1px 0 14px 10px;
                                    font-size:14px;
                                    line-height:1.6;
                                    color:#5c6266;
                                ">
                                    Pilih <strong style="color:#14181a;">Login dengan Google</strong>.
                                </td>
                            </tr>

                            <tr>
                                <td valign="top" width="32">
                                    <div style="
                                        width:24px;
                                        height:24px;
                                        line-height:24px;
                                        text-align:center;
                                        border-radius:50%;
                                        background:#f0f5f4;
                                        color:#044b46;
                                        font-size:12px;
                                        font-weight:700;
                                    ">3</div>
                                </td>
                                <td style="
                                    padding:1px 0 0 10px;
                                    font-size:14px;
                                    line-height:1.6;
                                    color:#5c6266;
                                ">
                                    Gunakan akun Google dengan email yang sama seperti undangan ini.
                                </td>
                            </tr>
                        </table>

                        {{-- CTA --}}
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td bgcolor="#044b46" style="border-radius:10px;">
                                    <a href="{{ url('/login') }}"
                                       style="
                                            display:inline-block;
                                            padding:13px 24px;
                                            color:#ffffff;
                                            text-decoration:none;
                                            font-size:14px;
                                            line-height:20px;
                                            font-weight:700;
                                            border-radius:10px;
                                       ">
                                        Masuk ke 523 Studio
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="
                            margin:28px 0 0;
                            font-size:13px;
                            line-height:1.7;
                            color:#767c80;
                        ">
                            Anda tidak perlu membuat kata sandi baru. Akses diberikan berdasarkan
                            alamat email Google yang telah didaftarkan oleh administrator 523 Studio.
                        </p>

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="
                        padding:22px 36px;
                        background:#fafbfc;
                        border-top:1px solid #eef0f4;
                    ">
                        <p style="
                            margin:0 0 5px;
                            font-size:12px;
                            line-height:1.6;
                            color:#767c80;
                        ">
                            Email ini dikirim karena alamat Anda didaftarkan sebagai anggota tim
                            523 Studio.
                        </p>

                        <p style="
                            margin:0;
                            font-size:12px;
                            line-height:1.6;
                            color:#9aa0a4;
                        ">
                            Jika Anda tidak mengenali undangan ini, abaikan email ini.
                        </p>
                    </td>
                </tr>

            </table>

            <p style="
                margin:20px 0 0;
                font-size:11px;
                color:#9aa0a4;
            ">
                &copy; {{ date('Y') }} 523 Studio
            </p>

        </td>
    </tr>
</table>

</body>
</html>