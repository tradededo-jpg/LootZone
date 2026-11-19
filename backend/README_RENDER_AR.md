# إعداد Backend الخاص بطلبات LootZone على Render

هذا الـ API هو نفس المسار المستعمل داخل التطبيق:
`/backend/api/order.php`

## متغيرات البيئة المطلوبة في خدمة Render الحالية

- `DATABASE_URL`: نفس رابط Neon PostgreSQL الموجود في خدمة لوحة الإدارة، دون تغيير.
- `TELEGRAM_BOT_TOKEN`: توكن بوت Telegram الجديد.
- `TELEGRAM_CHAT_ID`: رقم المحادثة أو المجموعة التي تستقبل الإشعارات.

لا تضع أيّاً من هذه القيم داخل APK أو GitHub.

## ما الذي يفعله endpoint؟

1. يتحقق من بيانات الطلب.
2. يحفظ الطلب في جدول `orders` في Neon، مع رقم طلب وحالة `processing`.
3. يرسل إشعار Telegram بعد الحفظ.
4. يحدّث `telegram_notified` عند نجاح الإشعار.
5. إذا تعطل Telegram يبقى الطلب محفوظاً وتظل استجابة التطبيق ناجحة، حتى لا يضيع طلب العميل.

تأكد أن خدمة Render الحالية تشغّل PHP مع إضافتي PDO PostgreSQL و cURL. لا تغيّر رابط API في `MainActivity.java`؛ المسار ما زال نفسه.
