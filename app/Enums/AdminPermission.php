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
    case CustomersView = 'customers.view';
    case CustomersContact = 'customers.contact';
    case CustomersManage = 'customers.manage';
    case CustomersLink = 'customers.link';
    case CustomersReconcile = 'customers.reconcile';
    case CustomersNotes = 'customers.notes';
    case ConsultationsView = 'consultations.view';
    case ConsultationsContact = 'consultations.contact';
    case ConsultationsManage = 'consultations.manage';
    case ConsultationsHistory = 'consultations.history';
    case InventoryView = 'inventory.view';
    case InventoryConfigure = 'inventory.configure';
    case InventoryReceive = 'inventory.receive';
    case InventoryAdjust = 'inventory.adjust';
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersReserve = 'orders.reserve';
    case OrdersConfirm = 'orders.confirm';
    case OrdersFulfill = 'orders.fulfill';
    case OrdersCustomerData = 'orders.customer_data';

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
            self::CustomersView => 'مشاهدهٔ فهرست و شناسهٔ پروفایل مشتری',
            self::CustomersContact => 'مشاهدهٔ نام و تماس مشتری',
            self::CustomersManage => 'ایجاد، ویرایش و بایگانی پروفایل مشتری',
            self::CustomersLink => 'اتصال و جداسازی تأییدشدهٔ سوابق مشتری',
            self::CustomersReconcile => 'تطبیق و انتقال کنترل‌شدهٔ پروفایل تکراری',
            self::CustomersNotes => 'مشاهدهٔ یادداشت‌ها و تاریخچهٔ خصوصی مشتری',
            self::ConsultationsView => 'مشاهدهٔ فرادادهٔ صندوق مشاوره',
            self::ConsultationsContact => 'مشاهدهٔ اطلاعات تماس و پرسش مشاوره',
            self::ConsultationsManage => 'تخصیص و مدیریت مرحلهٔ مشاوره',
            self::ConsultationsHistory => 'مشاهدهٔ یادداشت‌ها و سابقهٔ تماس مشاوره',
            self::InventoryView => 'مشاهدهٔ موجودی، سریال و گردش کالا',
            self::InventoryConfigure => 'تعریف کالا و آستانهٔ کمبود',
            self::InventoryReceive => 'ثبت ورود موجودی',
            self::InventoryAdjust => 'اصلاح مستدل موجودی آزاد',
            self::OrdersView => 'مشاهدهٔ سفارش‌ها و تاریخچهٔ غیرشخصی',
            self::OrdersCreate => 'ایجاد و ویرایش پیش‌نویس سفارش',
            self::OrdersReserve => 'رزرو، آزادسازی و لغو سفارش',
            self::OrdersConfirm => 'تأیید سفارش رزروشده',
            self::OrdersFulfill => 'ثبت تحویل کامل سفارش',
            self::OrdersCustomerData => 'مشاهدهٔ مشتری و اطلاعات خصوصی تحویل سفارش',
        };
    }
}
