<?php

namespace App\Enums;

enum AdminPermission: string
{
    case Dashboard = 'dashboard.view';
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case RepairsView = 'repairs.view';
    case RepairsCustomerData = 'repairs.customer_data';
    case RepairsIntake = 'repairs.intake';
    case RepairsAssign = 'repairs.assign';
    case RepairsInspect = 'repairs.inspect';
    case RepairsEstimate = 'repairs.estimate';
    case RepairsApprove = 'repairs.approve_estimates';
    case RepairsWork = 'repairs.work';
    case RepairsManage = 'repairs.transition';
    case RepairsDeliver = 'repairs.deliver';
    case RepairsPrint = 'repairs.print';
    case ActivityView = 'activity.view';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'مشاهدهٔ پیشخوان',
            self::ProductsView => 'مشاهدهٔ محصولات',
            self::ProductsManage => 'ایجاد، ویرایش و حذف محصول',
            self::RepairsView => 'مشاهدهٔ فهرست و اطلاعات غیرشخصی درخواست‌ها',
            self::RepairsCustomerData => 'مشاهدهٔ اطلاعات مشتری و شرح مشکل',
            self::RepairsIntake => 'پذیرش فیزیکی دستگاه',
            self::RepairsAssign => 'تخصیص و تغییر تکنسین',
            self::RepairsInspect => 'ثبت بررسی فنی پروندهٔ تخصیص‌یافته',
            self::RepairsEstimate => 'صدور نسخهٔ برآورد هزینه',
            self::RepairsApprove => 'ثبت تأیید یا رد برآورد مشتری با مدرک',
            self::RepairsWork => 'ثبت کار و تکمیل تعمیر پروندهٔ تخصیص‌یافته',
            self::RepairsManage => 'مدیریت صف، آمادگی و لغو تعمیر',
            self::RepairsDeliver => 'تحویل یا بازگرداندن دستگاه',
            self::RepairsPrint => 'مشاهدهٔ اسناد قابل چاپ تعمیر',
            self::ActivityView => 'مشاهدهٔ تاریخچهٔ فعالیت مدیریت',
        };
    }
}
