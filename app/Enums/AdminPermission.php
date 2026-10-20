<?php

namespace App\Enums;

enum AdminPermission: string
{
    case Dashboard = 'dashboard.view';
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case RepairsView = 'repairs.view';
    case RepairsCustomerData = 'repairs.customer_data';
    case ActivityView = 'activity.view';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'مشاهدهٔ پیشخوان',
            self::ProductsView => 'مشاهدهٔ محصولات',
            self::ProductsManage => 'ایجاد، ویرایش و حذف محصول',
            self::RepairsView => 'مشاهدهٔ فهرست و اطلاعات غیرشخصی درخواست‌ها',
            self::RepairsCustomerData => 'مشاهدهٔ اطلاعات مشتری و شرح مشکل',
            self::ActivityView => 'مشاهدهٔ تاریخچهٔ فعالیت مدیریت',
        };
    }
}
