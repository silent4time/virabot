<?php
// صفحهٔ ورودی مینی‌اپ نباید کش شود تا بعد از هر آپدیت فایل‌های جدید بارگذاری شوند.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Virabot</title>
    <script src="./js/telegram-web-app.js"></script>
    <script type="module" crossorigin src="./assets/index-TvERbYxt.js"></script>
    <link rel="modulepreload" crossorigin href="./assets/vendor-cSKCWCw4.js">
    <link rel="stylesheet" crossorigin href="./assets/index-it4lpJTR.css">
    <link rel="stylesheet" href="./virabot.css?v=2.2.2">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>