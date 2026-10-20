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
        };
    }
}
