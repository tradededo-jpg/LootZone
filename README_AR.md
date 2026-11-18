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
