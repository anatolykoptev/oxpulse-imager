#!/usr/bin/env python3
"""
Generate languages/oxpulse-imager-ru_RU.po from the .pot template
using a built-in Russian translation dictionary.

Run: python3 build/translate-ru.py
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
POT = ROOT / 'languages' / 'oxpulse-imager.pot'
PO = ROOT / 'languages' / 'oxpulse-imager-ru_RU.po'

# Russian translations. Keys are exact English msgid strings.
# Plural entries use (singular, plural) tuple → [tr_singular, tr_plural1, tr_plural2].
T = {
    # --- Admin nav ---
    "Settings": "Настройки",
    "OXPulse Imager": "OXPulse Imager",
    "You do not have permission to access this page.": "У вас нет прав для доступа к этой странице.",
    "OXPulse Imager admin failed to load. Try a hard refresh; if it still does not load, check for caching plugins or contact your site administrator.": "Админка OXPulse Imager не загрузилась. Попробуйте жёсткое обновление (Ctrl+Shift+R); если не поможет — проверьте плагины кэширования или свяжитесь с администратором сайта.",
    "OXPulse Imager diagnostics — click to view details": "Диагностика OXPulse Imager — нажмите для подробностей",

    # --- Runtime guards ---
    "OXPulse Imager requires PHP 8.3 or higher. You are running %s.": "OXPulse Imager требует PHP 8.3 или выше. У вас установлен %s.",
    "OXPulse Imager requires WordPress 6.2 or higher. You are running %s.": "OXPulse Imager требует WordPress 6.2 или выше. У вас установлен %s.",

    # --- Validator ---
    "Endpoint must use HTTPS in production. Enable dev HTTP override only for local development.": "Endpoint должен использовать HTTPS в production. HTTP разрешён только для локальной разработки через dev-override.",
    "Endpoint must be HTTP or HTTPS.": "Endpoint должен быть HTTP или HTTPS.",
    "Endpoint must be a valid URL or a relative path (e.g. /imgproxy).": "Endpoint должен быть валидным URL или относительным путём (напр. /imgproxy).",
    "Relative endpoint must start with / and contain only path characters.": "Относительный endpoint должен начинаться с / и содержать только символы пути.",
    "Key must be a non-empty even-length hexadecimal string.": "Ключ должен быть непустой hex-строкой чётной длины.",
    "Key must be at least %d bytes after hex decoding.": "Ключ должен быть не короче %d байт после hex-декодирования.",
    "Salt must be a non-empty even-length hexadecimal string.": "Соль должна быть непустой hex-строкой чётной длины.",
    "Salt must be at least %d bytes after hex decoding.": "Соль должна быть не короче %d байт после hex-декодирования.",
    "Allowed sources must be valid HTTP(S) URLs with trailing path boundary.": "Allowed sources должны быть валидными HTTP(S) URL с границей пути в конце.",
    "LQIP blur must be between %1$s and %2$s.": "Размытие LQIP должно быть между %1$s и %2$s.",
    "Local base path is not readable by the web server.": "Local base path не доступен для чтения веб-сервером.",
    "Local base path does not exist or is not a directory.": "Local base path не существует или не является директорией.",
    "Local base path must be an absolute filesystem path (starting with /).": "Local base path должен быть абсолютным путём файловой системы (начинаться с /).",
    "Local base path is required when source mode is \"local\".": "Local base path обязателен, когда source mode = \"local\".",
    "Save-Data quality reduction must be between 0 and 50.": "Снижение качества для Save-Data должно быть от 0 до 50.",
    "Size quality tier widths must be positive integers.": "Ширины tier-ов качества должны быть положительными целыми.",
    "Size quality tier values must be between 1 and 100.": "Значения tier-ов качества должны быть от 1 до 100.",
    "Size quality tier per-format values must be between 1 and 100.": "Значения tier-ов качества по форматам должны быть от 1 до 100.",
    "Quality for %1$s must be between %2$d and %3$d.": "Качество для %1$s должно быть между %2$d и %3$d.",
    "Watermark opacity must be between 0 and 1.": "Непрозрачность watermark должна быть от 0 до 1.",
    "Invalid watermark position.": "Невалидная позиция watermark.",
    "Watermark scale must be between 0 and 1.": "Масштаб watermark должен быть от 0 до 1.",

    # --- REST: status ---
    "Endpoint URL is empty.": "URL endpoint пуст.",
    "No sample image URL available. Configure allowed sources first.": "Нет URL тестового изображения. Сначала настройте allowed sources.",
    "Validation failed.": "Валидация не пройдена.",

    # --- REST: prewarm ---
    "No URLs provided.": "URL не переданы.",
    "Too many URLs. Maximum %d per batch.": "Слишком много URL. Максимум %d на батч.",
    "No valid URLs provided.": "Нет валидных URL.",
    "Delivery is disabled. Enable it in Connection settings first.": "Delivery отключён. Сначала включите его в настройках Connection.",
    "No imgproxy endpoint configured.": "imgproxy endpoint не настроен.",
    "No signing secrets configured.": "Signing secrets не настроены.",
    "Pre-warm job created. Poll GET /oxpulse/v1/prewarm/<jobId> for progress.": "Задача pre-warm создана. Опрашивайте GET /oxpulse/v1/prewarm/<jobId> для прогресса.",
    "No job ID provided.": "Не передан ID задачи.",
    "Job not found. It may have expired (jobs are kept for 1 hour).": "Задача не найдена. Возможно, она истекла (задачи хранятся 1 час).",

    # --- Admin SPA: top nav ---
    "Connection": "Подключение",
    "Format": "Формат",
    "Enhancements": "Улучшения",
    "Diagnostics": "Диагностика",
    "Tools": "Инструменты",
    "Pre-warm": "Прогрев кэша",
    "Loading settings…": "Загрузка настроек…",
    "Try again": "Попробовать снова",
    "imgproxy endpoint, signing secrets, and allowed source origins.": "endpoint imgproxy, signing secrets и allowed source origins.",
    "Output format and quality settings.": "Настройки формата вывода и качества.",
    "imgproxy-native features: LQIP placeholders, DPR-aware srcset, watermark.": "Нативные возможности imgproxy: LQIP-плейсхолдеры, DPR-aware srcset, watermark.",
    "Logging, development overrides, cleanup.": "Логирование, dev-override, очистка.",
    "Health check and AVIF format verification.": "Health check и проверка формата AVIF.",
    "Bulk pre-warm imgproxy cache for a batch of source image URLs.": "Массовый прогрев кэша imgproxy для батча URL изображений.",

    # --- Health check section ---
    "Health check": "Health check",
    "Verify that the configured imgproxy endpoint is reachable and reports healthy status.": "Проверяет, что настроенный endpoint imgproxy достижим и отвечает healthy-статусом.",
    "Checking…": "Проверка…",
    "Test connection": "Проверить подключение",
    "AVIF format check": "Проверка формата AVIF",
    "Verify that imgproxy is configured for AVIF format negotiation (IMGPROXY_AUTO_AVIF=true). Sends a request with Accept: image/avif and checks the response Content-Type.": "Проверяет, что imgproxy настроен на AVIF-негоциацию (IMGPROXY_AUTO_AVIF=true). Отправляет запрос с Accept: image/avif и проверяет Content-Type ответа.",
    "Sample image URL": "URL тестового изображения",
    "A publicly accessible image URL from your allowed sources. If empty, the first allowed source + /oxpulse-avif-test.jpg is used.": "Публично доступный URL изображения из ваших allowed sources. Если пусто — используется первый allowed source + /oxpulse-avif-test.jpg.",
    "Test AVIF support": "Проверить поддержку AVIF",

    # --- Prewarm section ---
    "Enter at least one URL.": "Введите хотя бы один URL.",
    "Maximum 50 URLs per batch.": "Максимум 50 URL на батч.",
    "Pre-warm failed.": "Прогрев не удался.",
    "Bulk pre-warm": "Массовый прогрев",
    "Trigger imgproxy to process + cache a batch of source image URLs NOW, so the first visitor does not pay the processing latency. HEAD requests are dispatched with concurrency=5. Max 50 URLs per batch.": "Запускает imgproxy для обработки + кэширования батча URL изображений прямо сейчас, чтобы первый посетитель не платил за latency обработки. HEAD-запросы отправляются с concurrency=5. Максимум 50 URL на батч.",
    "Source image URLs": "URL исходных изображений",
    "One URL per line. Only URLs matching your allowed sources will be warmed.": "По одному URL на строку. Прогреваются только URL, совпадающие с allowed sources.",
    "Target widths (optional)": "Целевые ширины (опционально)",
    "Comma-separated widths in px. Empty = no resize (the default variant). Max 5 widths.": "Ширины через запятую в px. Пусто = без resize (вариант по умолчанию). Максимум 5 ширин.",
    "Warming…": "Прогрев…",
    "Warm cache": "Прогреть кэш",
    "Total:": "Всего:",
    "Warmed:": "Прогрето:",
    "Skipped:": "Пропущено:",
    "Failed:": "Неудачно:",
    "Per-URL results": "Результаты по URL",

    # --- Format section ---
    "Output format": "Формат вывода",
    "Default output format": "Формат вывода по умолчанию",
    "auto = Accept header negotiation (AVIF/WebP/original based on browser support, requires IMGPROXY_AUTO_AVIF on the server). Explicit format overrides negotiation.": "auto = негоциация через Accept (AVIF/WebP/оригинал по поддержке браузера, требует IMGPROXY_AUTO_AVIF на сервере). Явный формат переопределяет негоциацию.",
    "Default quality": "Качество по умолчанию",
    "1–100. Used when a transform request does not specify quality.": "1–100. Используется, когда transform-запрос не указывает качество.",
    "AVIF quality": "Качество AVIF",
    "use default": "по умолчанию",
    "1–100. Overrides default for AVIF. AVIF looks good at 50-70.": "1–100. Переопределяет default для AVIF. AVIF хорошо выглядит при 50-70.",
    "WebP quality": "Качество WebP",
    "1–100. Overrides default for WebP. WebP looks good at 70-85.": "1–100. Переопределяет default для WebP. WebP хорошо выглядит при 70-85.",

    # --- Watermark positions ---
    "Center": "Центр",
    "North": "Север",
    "East": "Восток",
    "South": "Юг",
    "West": "Запад",
    "North-East": "Северо-восток",
    "North-West": "Северо-запад",
    "South-East": "Юго-восток",
    "South-West": "Юго-запад",
    "Replicate (tile)": "Реплика (тайл)",
    "Smart": "Smart",

    # --- Enhancements section ---
    "LQIP placeholders": "LQIP-плейсхолдеры",
    "Low-quality image placeholders — tiny blurred previews via imgproxy that reduce Cumulative Layout Shift (CLS). Falls back to inline SVG when imgproxy is unreachable.": "Low-quality image placeholders — крошечные размытые превью через imgproxy, снижающие Cumulative Layout Shift (CLS). Falls back на inline SVG, если imgproxy недоступен.",
    "Emit data-placeholder on img tags": "Эмитить data-placeholder на img-тегах",
    "Blur sigma": "Сигма размытия",
    "0.1–100. Higher = more blur. 1 is a good default.": "0.1–100. Больше = сильнее размытие. 1 — хороший default.",
    "DPR-aware srcset": "DPR-aware srcset",
    "For img tags with width but no srcset, generates 1x/2x/3x x-descriptor variants via imgproxy dpr: option. Images that already have w-descriptor srcset are left alone.": "Для img-тегов с width, но без srcset, генерирует 1x/2x/3x x-descriptor варианты через опцию imgproxy dpr:. Изображения с w-descriptor srcset не трогаются.",
    "Generate DPR variants for images without srcset": "Генерировать DPR-варианты для изображений без srcset",
    "DPR multipliers": "DPR-множители",
    "Comma-separated, 1–8. e.g. 1,2,3 for standard/retina/hyper-retina.": "Через запятую, 1–8. напр. 1,2,3 для standard/retina/hyper-retina.",
    "Watermark": "Watermark",
    "Applies imgproxy native watermark (wm: option). The watermark image is configured server-side via IMGPROXY_WATERMARK_PATH/URL — this setting controls placement only.": "Применяет нативный watermark imgproxy (опция wm:). Изображение watermark настраивается на сервере через IMGPROXY_WATERMARK_PATH/URL — эта настройка управляет только размещением.",
    "Apply watermark": "Применить watermark",
    "Opacity": "Непрозрачность",
    "0 = transparent, 1 = opaque.": "0 = прозрачно, 1 = непрозрачно.",
    "Position": "Позиция",
    "X offset (px)": "X-смещение (px)",
    "Y offset (px)": "Y-смещение (px)",
    "Scale": "Масштаб",
    "0 = auto-size, 0.1 = 10% of source image. Relative to source dimensions.": "0 = auto-size, 0.1 = 10% исходного изображения. Относительно размеров источника.",

    # --- Diagnostics section ---
    "Failed to load diagnostics.": "Не удалось загрузить диагностику.",
    "Failed to clear log.": "Не удалось очистить лог.",
    "Off (silent)": "Off (тихо)",
    "Basic (per-request counts)": "Basic (счётчики на запрос)",
    "Verbose (per-URL with reason)": "Verbose (по URL с причиной)",
    "Diagnostic level": "Уровень диагностики",
    "Controls what gets written to the PHP error log on each page load. Off = no logging. Basic = one summary line per request (counts by context). Verbose = per-URL entries with reason + redacted source URL.": "Контролирует, что пишется в PHP error log при каждой загрузке страницы. Off = нет логирования. Basic = одна summary-строка на запрос (счётчики по context). Verbose = по-URL записи с причиной + редуцированным source URL.",
    "Level": "Уровень",
    "Changes take effect on the next page load. The admin bar item (frontend) shows live counts for the current page.": "Изменения вступят в силу при следующей загрузке страницы. Элемент admin bar (frontend) показывает live-счётчики для текущей страницы.",
    "Recent log entries": "Недавние записи лога",
    "Recent diagnostic entries from the last few requests. Entries are kept for 1 hour. Source URLs are redacted (host + truncated path only).": "Недавние диагностические записи за последние несколько запросов. Записи хранятся 1 час. Source URL редуцированы (только host + укороченный path).",
    "Loading…": "Загрузка…",
    "Refresh": "Обновить",
    "Clearing…": "Очистка…",
    "Clear log": "Очистить лог",
    "Diagnostics are off. Set a level above and save to start logging.": "Диагностика выключена. Установите уровень выше и сохраните, чтобы начать логирование.",
    "No entries yet. Visit a frontend page with images to generate log entries.": "Записей пока нет. Откройте frontend-страницу с изображениями, чтобы сгенерировать записи.",

    # --- Connection section ---
    "Secrets configured. Values are hidden for security.": "Secrets настроены. Значения скрыты для безопасности.",
    "Partial secrets detected. Please set both key and salt.": "Обнаружены частичные secrets. Установите и key, и salt.",
    "No secrets configured. Generate a key and salt to enable signed URL delivery.": "Secrets не настроены. Сгенерируйте key и salt, чтобы включить подписанную доставку URL.",
    "Delivery": "Доставка",
    "Enable delivery": "Включить доставку",
    "Rewrite approved image URLs to signed imgproxy URLs. When disabled, the plugin is a complete no-op on the frontend.": "Переписывает одобренные URL изображений в подписанные imgproxy URL. Когда отключено — плагин полностью no-op на frontend.",
    "imgproxy endpoint": "endpoint imgproxy",
    "Endpoint URL": "URL endpoint",
    "Base URL of your self-hosted imgproxy instance. HTTPS required in production.": "Base URL вашего self-hosted imgproxy. HTTPS обязателен в production.",
    "Signing secrets": "Signing secrets",
    "Hex-encoded imgproxy key + salt. Minimum 16 bytes after decoding. Never displayed after save — leave empty to keep existing.": "Hex-кодированные imgproxy key + salt. Минимум 16 байт после декодирования. Никогда не отображаются после сохранения — оставьте пустым, чтобы сохранить существующие.",
    "Signing key (hex)": "Signing key (hex)",
    "Leave empty to keep existing": "Оставьте пустым, чтобы сохранить существующее",
    "Signing salt (hex)": "Signing salt (hex)",
    "Allowed source origins": "Allowed source origins",
    "One URL prefix per line. Only images whose URL starts with one of these prefixes will be rewritten. A trailing slash enforces a path boundary.": "По одному URL-префиксу на строку. Переписываются только изображения, чей URL начинается с одного из этих префиксов. Trailing slash обозначает границу пути.",

    # --- Onboarding wizard ---
    "Step": "Шаг",
    "Failed to save settings.": "Не удалось сохранить настройки.",
    "Please enter a valid HTTPS endpoint URL.": "Введите валидный HTTPS URL endpoint.",
    "Please enter both key and salt, or generate them.": "Введите и key, и salt, или сгенерируйте их.",
    "Key and salt must be at least 16 bytes (32 hex characters).": "Key и salt должны быть не короче 16 байт (32 hex-символа).",
    "Failed to save secrets.": "Не удалось сохранить secrets.",
    "Generated 32-byte key + salt. You can copy these before continuing — they will not be shown again after save.": "Сгенерированы 32-байтные key + salt. Скопируйте их перед продолжением — они не будут показаны снова после сохранения.",
    "Health check failed. Verify the endpoint URL and that imgproxy is running.": "Health check не прошёл. Проверьте URL endpoint и что imgproxy запущен.",
    "Health check request failed.": "Запрос health check не удался.",
    "Please add at least one allowed source origin.": "Добавьте хотя бы один allowed source origin.",
    "Added uploads URL to allowed sources.": "URL uploads добавлен в allowed sources.",
    "Uploads URL is already in allowed sources.": "URL uploads уже в allowed sources.",
    "Could not auto-detect uploads URL. Please enter it manually.": "Не удалось авто-определить URL uploads. Введите его вручную.",
    "AVIF is not supported by your imgproxy build. The plugin will fall back to WebP. You can continue.": "AVIF не поддерживается вашей сборкой imgproxy. Плагин будет fallback-ать на WebP. Можно продолжить.",
    "AVIF check request failed.": "Запрос проверки AVIF не удался.",
    "Failed to enable delivery.": "Не удалось включить доставку.",
    "Welcome to OXPulse Imager": "Добро пожаловать в OXPulse Imager",
    "Skip for now": "Пропустить",
    "Enter the base URL of your self-hosted imgproxy instance. HTTPS is required in production.": "Введите base URL вашего self-hosted imgproxy. HTTPS обязателен в production.",
    "No trailing slash. Example: https://imgproxy.yourdomain.com": "Без trailing slash. Пример: https://imgproxy.yourdomain.com",
    "imgproxy requires a key + salt to sign transformed image URLs. Generate random 32-byte secrets, or paste your own hex-encoded values.": "imgproxy требует key + salt для подписи трансформированных URL изображений. Сгенерируйте случайные 32-байтные secrets или вставьте свои hex-кодированные значения.",
    "Generate random key + salt": "Сгенерировать случайные key + salt",
    "64 hex characters (32 bytes)": "64 hex-символа (32 байта)",
    "Verify that imgproxy is reachable and responding at the endpoint you configured.": "Проверяет, что imgproxy достижим и отвечает по настроенному endpoint.",
    "Endpoint:": "Endpoint:",
    "Testing…": "Тестирование…",
    "Connected — imgproxy is responding.": "Подключено — imgproxy отвечает.",
    "Only images whose URL starts with one of these prefixes will be rewritten. Add your wp-content/uploads/ URL.": "Переписываются только изображения, чей URL начинается с одного из этих префиксов. Добавьте URL вашего wp-content/uploads/.",
    "Auto-detect uploads URL": "Авто-определить URL uploads",
    "Check whether your imgproxy build supports AVIF output. If not, the plugin falls back to WebP automatically.": "Проверяет, поддерживает ли ваша сборка imgproxy вывод AVIF. Если нет — плагин автоматически fallback-ает на WebP.",
    "Test AVIF": "Тест AVIF",
    "AVIF supported — your imgproxy can serve AVIF.": "AVIF поддерживается — ваш imgproxy может отдавать AVIF.",
    "AVIF not supported — will fall back to WebP.": "AVIF не поддерживается — fallback на WebP.",
    "Everything is configured. Enable delivery to start rewriting image URLs to signed imgproxy URLs on the frontend.": "Всё настроено. Включите доставку, чтобы начать переписывать URL изображений в подписанные imgproxy URL на frontend.",
    "Allowed sources:": "Allowed sources:",
    "AVIF:": "AVIF:",
    "Supported": "Поддерживается",
    "Not supported (WebP fallback)": "Не поддерживается (WebP fallback)",
    "Delivery:": "Доставка:",
    "Will be enabled on finish": "Будет включена при завершении",
    "Back": "Назад",
    "Saving…": "Сохранение…",
    "Next": "Далее",
    "Enabling…": "Включение…",
    "Settings saved.": "Настройки сохранены.",
    "Settings sections": "Разделы настроек",
    "Unsaved changes": "Несохранённые изменения",
    "Save": "Сохранить",

    # --- Misc ---
    "No source URL provided.": "Не передан source URL.",

    # --- Health check service (REST API messages) ---
    "Endpoint URL is empty.": "URL endpoint пуст.",
    "Endpoint URL is malformed.": "URL endpoint некорректен.",
    "Endpoint responded but health check path was not found.": "Endpoint ответил, но путь health check не найден.",
    "imgproxy returned a server error.": "imgproxy вернул ошибку сервера.",
    "Unexpected response status.": "Неожиданный статус ответа.",
    "Sample image URL is empty.": "URL тестового изображения пуст.",
    "imgproxy returned non-200 for format negotiation check.": "imgproxy вернул не-200 для проверки согласования формата.",
    "AVIF format negotiation is supported.": "Согласование формата AVIF поддерживается.",
    "imgproxy returned WebP, not AVIF. Enable IMGPROXY_AUTO_AVIF on the server for AVIF delivery.": "imgproxy вернул WebP вместо AVIF. Включите IMGPROXY_AUTO_AVIF на сервере для отдачи AVIF.",
    "imgproxy did not return AVIF. Check IMGPROXY_AUTO_AVIF configuration. Got Content-Type: %s": "imgproxy не вернул AVIF. Проверьте конфигурацию IMGPROXY_AUTO_AVIF. Получен Content-Type: %s",
    "Connection successful.": "Подключение успешно.",
    "imgproxy returned HTTP %d": "imgproxy вернул HTTP %d",
    "OK": "OK",
    "WordPress HTTP API not available.": "WordPress HTTP API недоступен.",
    "cURL extension not available.": "Расширение cURL недоступно.",
    "Unknown error.": "Неизвестная ошибка.",

    # --- Admin bar ---
    "OXPulse: %1$d rewritten, %2$d preserved": "OXPulse: %1$d переписано, %2$d сохранено",

    # --- WP-CLI abstract ---
    "Success: %s": "Успешно: %s",
    "Warning: %s": "Предупреждение: %s",

    # --- WP-CLI flush ---
    "Flushed %d cache entry/entries.": "Очищено %d записей кэша.",
    "Note: imgproxy's own cache is not cleared — use your CDN/imgproxy purge API for that.": "Примечание: собственный кэш imgproxy не очищается — используйте API purge вашего CDN/imgproxy.",

    # --- WP-CLI info ---
    "Usage: wp oxpulse info <url> [--width=<n>]": "Использование: wp oxpulse info <url> [--width=<n>]",
    "Source URL: %s": "Исходный URL: %s",
    "Target width: %s": "Целевая ширина: %s",
    "no resize": "без масштабирования",
    "Delivery enabled: %s": "Доставка включена: %s",
    "yes": "да",
    "no": "нет",
    "Delivery is disabled — the source URL would NOT be rewritten on the frontend.": "Доставка отключена — исходный URL НЕ будет переписан на фронтенде.",
    "No endpoint configured — the source URL would NOT be rewritten.": "Endpoint не настроен — исходный URL НЕ будет переписан.",
    "No signing secrets configured — the source URL would NOT be rewritten.": "Секреты подписи не настроены — исходный URL НЕ будет переписан.",
    "Result: %s": "Результат: %s",
    "REWRITTEN": "ПЕРЕПИСАН",
    "PRESERVED": "СОХРАНЁН",
    "Reason: %s": "Причина: %s",
    "imgproxy URL:": "URL imgproxy:",

    # --- WP-CLI status ---
    "OXPulse Imager status": "Статус OXPulse Imager",
    "Endpoint: %s": "Endpoint: %s",
    "(not configured)": "(не настроен)",
    "Output format: %s": "Формат вывода: %s",
    "Default quality: %s": "Качество по умолчанию: %s",
    "Allowed sources: %d": "Разрешённые источники: %d",
    "  - %s": "  - %s",
    "Signing: %s": "Подпись: %s",
    "configured (key+salt set)": "настроена (key+salt заданы)",
    "NOT configured": "НЕ настроена",
    "LQIP: %s": "LQIP: %s",
    "enabled (blur=%s)": "включён (blur=%s)",
    "disabled": "отключён",
    "DPR srcset: %s": "DPR srcset: %s",
    "enabled (%s)": "включён (%s)",
    "enabled": "включён",
    "Watermark: %s": "Водяной знак: %s",
    "Diagnostic level: %s": "Уровень диагностики: %s",
    "Health check: (skipped via --no-health)": "Health check: (пропущен через --no-health)",
    "Health check: no endpoint configured.": "Health check: endpoint не настроен.",
    "Health check...": "Health check...",
    "imgproxy health: %s — %s": "Health imgproxy: %s — %s",
    "imgproxy reachable: %s": "imgproxy доступен: %s",
    "AVIF check (sample: %s)...": "Проверка AVIF (тест: %s)...",
    "AVIF: %s": "AVIF: %s",
    "AVIF supported: %s": "AVIF поддерживается: %s",

    # --- WP-CLI warm ---
    "Delivery is disabled. Enable it in Settings > OXPulse Imager first.": "Доставка отключена. Сначала включите её в Настройки > OXPulse Imager.",
    "No imgproxy endpoint configured.": "Endpoint imgproxy не настроен.",
    "No signing secrets configured.": "Секреты подписи не настроены.",
    "No URLs to warm. Provide URLs as args, or use --attachment=<id> / --all.": "Нет URL для прогрева. Передайте URL как аргументы или используйте --attachment=<id> / --all.",
    "Warming %d URL(s) at widths: %s": "Прогрев %d URL(ов) на ширинах: %s",
    "Batch %1$d/%2$d (%3$d URLs)...": "Батч %1$d/%2$d (%3$d URL)...",
    "  [%1$s] %2$s — %3$s": "  [%1$s] %2$s — %3$s",
    "Done. Total: %1$d, Warmed: %2$d, Skipped: %3$d, Failed: %4$d": "Готово. Всего: %1$d, Прогрето: %2$d, Пропущено: %3$d, Ошибок: %4$d",
    "%d URL(s) warmed.": "%d URL(ов) прогрето.",
    "%d URL(s) failed to warm.": "%d URL(ов) не удалось прогреть.",
    "WordPress functions not available (wp_get_attachment_url).": "Функции WordPress недоступны (wp_get_attachment_url).",
    "WordPress functions not available (get_posts).": "Функции WordPress недоступны (get_posts).",
    "Enumerating media library...": "Перечисление медиатеки...",
    "Found %d attachment(s).": "Найдено %d вложений.",

    # --- JS UI ---
    "%d failed": "%d ошибок",
    "%d skipped": "%d пропущено",
    "%d warmed": "%d прогрето",
    "Status": "Статус",
    "Message": "Сообщение",
    "auto (Accept negotiation)": "auto (согласование Accept)",
    "level: ": "уровень: ",
    "Context": "Контекст",
    "Reason": "Причина",
    "rewritten": "переписан",
    "preserved": "сохранён",
    "Collapse section": "Свернуть раздел",
    "Expand section": "Развернуть раздел",
    "Dismiss": "Закрыть",
}


def escape_po(s: str) -> str:
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def main() -> int:
    if not POT.exists():
        print(f'✗ {POT} not found. Run `npm run make-pot` first.', file=sys.stderr)
        return 1

    pot = POT.read_text(encoding='utf-8')

    # Split into entries (separated by blank lines).
    # The first block is the header.
    blocks = re.split(r'\n\n+', pot.strip())

    header = blocks[0]
    # Patch the header: set Language and plural-forms for Russian.
    header = re.sub(r'^"Language:.*\\n"$', '"Language: ru_RU\\\\n"', header, flags=re.M)
    header = re.sub(r'^"Plural-Forms:.*\\n"\s*$', '"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\\\\n"', header, flags=re.M)
    # Add Language-Team if missing.
    if 'Language-Team:' not in header:
        header = header.replace('"Last-Translator:',
                                '"Language-Team: ru <koptev@koptev.org>\\n"\n"Last-Translator:')

    out = [header, '']

    missing = []
    translated = 0
    for block in blocks[1:]:
        # Extract msgid (may span multiple lines).
        msgid_match = re.search(r'^msgid "(.*?)"$', block, re.M)
        if not msgid_match:
            continue
        # Handle multi-line msgid: concatenate continuation lines.
        msgid = msgid_match.group(1)
        # Find continuation lines (bare quoted strings after the msgid line).
        lines = block.split('\n')
        msgid_idx = next(i for i, l in enumerate(lines) if l.startswith('msgid '))
        for l in lines[msgid_idx + 1:]:
            if l.startswith('"'):
                msgid += re.match(r'^"(.*?)"$', l).group(1)
            else:
                break
        # Unescape.
        msgid = msgid.replace('\\"', '"').replace('\\n', '\n').replace('\\\\', '\\')

        # Find msgid_plural if present.
        msgid_plural = None
        plural_match = re.search(r'^msgid_plural "(.*?)"$', block, re.M)
        if plural_match:
            msgid_plural = plural_match.group(1)
            # Continuation lines for plural.
            plural_idx = next(i for i, l in enumerate(lines) if l.startswith('msgid_plural '))
            for l in lines[plural_idx + 1:]:
                if l.startswith('"'):
                    msgid_plural += re.match(r'^"(.*?)"$', l).group(1)
                else:
                    break
            msgid_plural = msgid_plural.replace('\\"', '"').replace('\\n', '\n').replace('\\\\', '\\')

        # Look up translation.
        key = msgid if not msgid_plural else (msgid, msgid_plural)
        tr = T.get(msgid)
        if tr is None:
            missing.append(msgid)
            # Leave msgstr empty.
            out.append(block)
            out.append('')
            continue

        translated += 1
        # Rebuild the block with the translation.
        # Keep #: references and msgctxt, replace msgstr.
        new_lines = []
        in_msgstr = False
        msgstr_written = False
        for l in lines:
            if l.startswith('msgstr '):
                if msgid_plural:
                    # Plural: write msgstr[0], msgstr[1], msgstr[2].
                    # For simplicity, use the same translation for all plural forms
                    # (translator can refine later). Russian has 3 plural forms.
                    new_lines.append(f'msgstr[0] "{escape_po(tr)}"')
                    new_lines.append(f'msgstr[1] "{escape_po(tr)}"')
                    new_lines.append(f'msgstr[2] "{escape_po(tr)}"')
                else:
                    new_lines.append(f'msgstr "{escape_po(tr)}"')
                in_msgstr = True
                msgstr_written = True
                # Skip continuation lines of the original msgstr.
                continue
            if in_msgstr and l.startswith('"'):
                # Skip continuation lines of the original msgstr.
                continue
            if in_msgstr and l.startswith('msgstr['):
                # Skip original plural msgstr[] lines.
                continue
            in_msgstr = False
            new_lines.append(l)
        if not msgstr_written:
            # No msgstr line in the block (shouldn't happen for valid POT).
            if msgid_plural:
                new_lines.append(f'msgstr[0] "{escape_po(tr)}"')
                new_lines.append(f'msgstr[1] "{escape_po(tr)}"')
                new_lines.append(f'msgstr[2] "{escape_po(tr)}"')
            else:
                new_lines.append(f'msgstr "{escape_po(tr)}"')
        out.append('\n'.join(new_lines))
        out.append('')

    PO.write_text('\n'.join(out) + '\n', encoding='utf-8')
    print(f'✓ Wrote {PO}')
    print(f'  Translated: {translated}')
    print(f'  Missing:    {len(missing)}')
    if missing:
        print('  Missing msgids:')
        for m in missing[:20]:
            print(f'    - {m[:80]}')
        if len(missing) > 20:
            print(f'    ... and {len(missing) - 20} more')
    return 0


if __name__ == '__main__':
    sys.exit(main())
