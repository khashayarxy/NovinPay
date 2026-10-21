<?php

namespace App\Enums;

enum AdminAction: string
{
    case Bootstrap = 'super_admin.bootstrapped';
    case StaffCreated = 'staff.created';
    case StaffUpdated = 'staff.updated';
    case PermissionsChanged = 'staff.permissions_changed';
    case Activated = 'staff.activated';
    case Deactivated = 'staff.deactivated';
    case PasswordReset = 'staff.password_reset';
    case PasswordChanged = 'security.password_changed';
    case Login = 'security.login';
    case Logout = 'security.logout';
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case RepairCustomerViewed = 'repair.customer_data_viewed';
    case RepairIntake = 'repair.intake';
    case RepairAssigned = 'repair.assigned';
    case RepairTransition = 'repair.transition';
    case RepairInspection = 'repair.inspection';
    case RepairEstimate = 'repair.estimate';
    case RepairDecision = 'repair.estimate_decision';
    case RepairWork = 'repair.work';
    case RepairDelivery = 'repair.delivery';
    case RepairDocument = 'repair.document_viewed';
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerLinked = 'customer.linked';
    case CustomerUnlinked = 'customer.unlinked';
    case CustomerReconciled = 'customer.reconciled';
    case CustomerNote = 'customer.note';
    case CustomerViewed = 'customer.contact_viewed';
    case ConsultationViewed = 'consultation.contact_viewed';
    case ConsultationAssigned = 'consultation.assigned';
    case ConsultationTransition = 'consultation.transition';
    case ConsultationEntry = 'consultation.entry';
    case InventoryConfigured = 'inventory.configured';
    case InventoryReceived = 'inventory.received';
    case InventoryAdjusted = 'inventory.adjusted';
    case OrderChanged = 'order.changed';
    case OrderCustomerViewed = 'order.customer_viewed';

    public function label(): string
    {
        return match ($this) {
            self::Bootstrap => 'تعیین مدیر کل محافظت‌شده',
            self::StaffCreated => 'ایجاد حساب همکار با نقش کارمند',
            self::StaffUpdated => 'ویرایش حساب همکار',
            self::PermissionsChanged => 'تغییر مجوزهای همکار',
            self::Activated => 'فعال‌سازی حساب',
            self::Deactivated => 'غیرفعال‌سازی حساب',
            self::PasswordReset => 'بازنشانی گذرواژهٔ همکار',
            self::PasswordChanged => 'تغییر گذرواژهٔ شخصی',
            self::Login => 'ورود موفق به مدیریت',
            self::Logout => 'خروج از مدیریت',
            self::ProductCreated => 'ایجاد محصول',
            self::ProductUpdated => 'ویرایش محصول',
            self::ProductDeleted => 'حذف محصول',
            self::RepairCustomerViewed => 'مشاهدهٔ اطلاعات شخصی درخواست تعمیر',
            self::RepairIntake => 'پذیرش فیزیکی دستگاه', self::RepairAssigned => 'تغییر تخصیص تکنسین',
            self::RepairTransition => 'تغییر مرحلهٔ عملیاتی تعمیر', self::RepairInspection => 'ثبت بررسی فنی',
            self::RepairEstimate => 'صدور نسخهٔ برآورد', self::RepairDecision => 'ثبت تصمیم مشتری توسط کارمند',
            self::RepairWork => 'ثبت کار تعمیر', self::RepairDelivery => 'ثبت تحویل دستگاه',
            self::RepairDocument => 'مشاهدهٔ سند قابل چاپ تعمیر',
            self::CustomerCreated => 'ایجاد پروفایل مشتری', self::CustomerUpdated => 'ویرایش یا بایگانی پروفایل مشتری',
            self::CustomerLinked => 'اتصال تأییدشدهٔ سابقه به مشتری', self::CustomerUnlinked => 'جداسازی سابقه از مشتری',
            self::CustomerReconciled => 'تطبیق کنترل‌شدهٔ پروفایل تکراری', self::CustomerNote => 'افزودن یادداشت مشتری',
            self::CustomerViewed => 'مشاهدهٔ تماس مشتری', self::ConsultationViewed => 'مشاهدهٔ اطلاعات خصوصی مشاوره',
            self::ConsultationAssigned => 'تخصیص مشاوره', self::ConsultationTransition => 'تغییر مرحلهٔ مشاوره',
            self::ConsultationEntry => 'ثبت یادداشت یا تلاش تماس مشاوره',
            self::InventoryConfigured => 'تعریف کالا یا تغییر آستانهٔ موجودی',
            self::InventoryReceived => 'ثبت ورود موجودی',
            self::InventoryAdjusted => 'ثبت اصلاح موجودی با دلیل',
            self::OrderChanged => 'عملیات داخلی سفارش',
            self::OrderCustomerViewed => 'مشاهدهٔ اطلاعات خصوصی مشتری سفارش',
        };
    }
}
