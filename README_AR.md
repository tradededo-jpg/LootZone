# LootZone Admin — مشروع مستقل

لوحة إدارة مستقلة عن تطبيق LootZone، تُنشر كخدمة Docker منفصلة على Render وتتصل مباشرة بقاعدة PostgreSQL على Neon.

## محتويات المشروع
- `public/index.php`: واجهة لوحة الإدارة العربية.
- `public/api.php`: تسجيل الدخول، جلب الطلبات، وتحديث حالة الطلب.
- `public/db.php`: اتصال PostgreSQL عبر `DATABASE_URL`.
- `schema.sql`: إنشاء جدول الطلبات المتوافق مع API المتجر.
- `Dockerfile`: تشغيل PHP + Apache + PDO PostgreSQL على Render.
- `render.yaml`: إعداد خدمة Render.

## 1) تجهيز Neon
في Neon افتح SQL Editor ونفّذ محتوى `schema.sql` مرة واحدة.
انسخ Connection string بصيغة PostgreSQL مع SSL.

## 2) رفع المشروع إلى GitHub
أنشئ مستودعاً جديداً باسم `LootZone-Admin`، وارفع جميع ملفات هذا المجلد إلى جذر المستودع (لا ترفع المجلد الأب نفسه).

## 3) النشر على Render
- Render → New → Web Service.
- اربط مستودع `LootZone-Admin`.
- اختر Runtime: Docker.
- أنشئ الخدمة.
- في Environment أضف:
  - `DATABASE_URL`: رابط Neon الكامل.
  - `ADMIN_USERNAME`: اسم مستخدم الإدارة.
  - `ADMIN_PASSWORD`: كلمة مرور قوية وفريدة.
- Deploy.

سيعطيك Render رابطاً مستقلاً شبيهاً بـ `https://lootzone-admin.onrender.com`.

## 4) ربط المتجر
يجب أن يرسل Backend المتجر الطلبات إلى جدول `orders` نفسه في Neon وبنفس الأعمدة الموجودة في `schema.sql`.
هذه اللوحة تقرأ وتحدّث هذا الجدول مباشرة. لا تضع كلمات المرور أو `DATABASE_URL` داخل تطبيق Android أو ملفات JavaScript.

## ملاحظات مهمة
- رابط Render لا يمكن استخراجه قبل أن تنشئ الخدمة وتنشرها من حسابك.
- هذه اللوحة مستقلة في المستودع والخدمة، لكنها تعتمد على قاعدة Neon المشتركة مع Backend المتجر.
- لا تضع بيانات دخول الإدارة داخل GitHub. أدخلها في Render Environment فقط.
- تأكد من أن خدمة المتجر وخدمة الإدارة تستخدمان قاعدة Neon نفسها.

## ملاحظة ربط نسخة LootZone الحالية

تطبيق Android يرسل الطلبات إلى `https://lootzone-2rqc.onrender.com/backend/api/order.php`. يجب أن تكون خدمة Render على هذا النطاق هي خدمة الـ Backend التي تحتوي مجلد `backend` من مشروع التطبيق، وأن تحتوي متغير `DATABASE_URL` نفسه المستخدم في لوحة الإدارة. عند نشر تحديث Backend لا تغيّر رابط `API_URL` في التطبيق.

## نشر موحّد: لوحة الإدارة + API التطبيق على نفس الرابط

هذه النسخة تحتوي الآن على المسارين في خدمة Render نفسها:
- لوحة الإدارة: `/`
- استقبال طلبات تطبيق Android: `/backend/api/order.php`

ملف Dockerfile ينسخ مجلد `backend` إلى مسار الويب ويثبت إضافتي PHP `pdo_pgsql` و`curl` اللازمتين. أضف في Environment على خدمة Render المتغيرات التالية:
- `DATABASE_URL`: نفس رابط Neon المستخدم حالياً.
- `ADMIN_USERNAME`: اسم دخول لوحة الإدارة.
- `ADMIN_PASSWORD`: كلمة مرور قوية.
- `TELEGRAM_BOT_TOKEN`: توكن البوت الجديد.
- `TELEGRAM_CHAT_ID`: Chat ID.

بعد نشر هذه النسخة على **نفس خدمة Render ونفس المستودع الذي يعطيك الرابط الحالي**، سيعمل التطبيق على نفس `API_URL` الموجود في `MainActivity.java` دون تعديل. لا تنشئ خدمة ثانية ولا تغيّر رابط Neon.

مهم: لا تضع قيم Environment الحقيقية في GitHub أو داخل ZIP. أضفها من Render فقط. بعد النشر اختبر `/` لفتح لوحة الإدارة، ثم أرسل طلباً تجريبياً من التطبيق وتأكد من ظهوره في لوحة الإدارة.
