<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Codice OTP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    html, body {
      margin: 0;
      padding: 0;
      height: 100%;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #111827;
      color: #f9fafb;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .container {
      text-align: center;
      padding: 2rem;
    }
    .title {
      font-size: 1.4rem;
      margin-bottom: 1rem;
      color: #9ca3af;
    }
    .code {
      font-size: 3.5rem;
      letter-spacing: 0.4rem;
      font-weight: 700;
      padding: 1rem 2rem;
      border-radius: 1rem;
      border: 2px solid #4b5563;
      background: #030712;
      display: inline-block;
      min-width: 10rem;
      cursor: pointer;
      user-select: none;
    }
    .hint {
      margin-top: 1.5rem;
      font-size: 0.9rem;
      color: #6b7280;
    }

    /* Feedback copiata */
    .toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      background: #10b981;
      color: #fff;
      padding: 12px 20px;
      border-radius: 10px;
      font-size: 14px;
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    .toast.show {
      opacity: 1;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="title">Il tuo codice OTP</div>
    <div class="code" id="otpCode"><?= esc($code) ?></div>
    <div class="hint">Tocca il codice per copiarlo.</div>
  </div>

  <div class="toast" id="toast">Codice copiato!</div>

  <script>
    // copia OTP al click (Android, iOS, desktop, PWA)
    document.getElementById("otpCode").addEventListener("click", async () => {
      const code = document.getElementById("otpCode").innerText.trim();

      try {
        // API moderna per copiare
        await navigator.clipboard.writeText(code);
      } catch (e) {
        // fallback per browser vecchi
        const tmp = document.createElement("input");
        tmp.value = code;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand("copy");
        tmp.remove();
      }

      // mostra feedback
      const toast = document.getElementById("toast");
      toast.classList.add("show");
      setTimeout(() => toast.classList.remove("show"), 1500);
    });
  </script>

</body>
</html>
