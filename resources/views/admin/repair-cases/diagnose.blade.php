@if(in_array($stage,['received','waiting_inspection','inspecting','inspected','waiting_approval','approved']))
@can('operate', [$case, 'diagnose'])
<section class="repair-action-detail" ><h2>عیب‌یابی و برآورد</h2>
<x-repair-operation :case="$case" action="diagnose" title="ثبت عیب‌یابی و قیمت">
<div class="admin-form-grid">
<x-operation-field name="diagnosis" label="تشخیص عیب" kind="textarea"/>
<x-operation-field name="customer_summary" label="تعمیر پیشنهادی" kind="textarea"/>
</div>
<div class="admin-form-grid">
<x-operation-field name="service_cost" label="دستمزد تعمیر (تومان)" type="number"/>
<x-operation-field name="parts_cost" label="قطعات (تومان)" type="number" value="0"/>
</div>
<x-operation-field name="diagnosis_fee" label="هزینهٔ عیب‌یابی در صورت انصراف (تومان)" type="number" value="0"/>
<p class="admin-hint">تغییر برآورد به تأیید تازهٔ مشتری نیاز دارد. هزینهٔ عیب‌یابی جدا از تعمیر موفق دریافت نمی‌شود.</p>
<button class="btn btn-primary">ثبت و انتظار پاسخ مشتری</button>
</x-repair-operation></section>
@endcan
@endif
@if($stage === 'waiting_approval' && $estimates->first())
@can('operate', [$case, 'contact'])<section class="repair-action-detail"><h2>مشتری پاسخ نداد</h2>
<x-repair-operation :case="$case" action="contact" title="ثبت تلاش تماس"><input type="hidden" name="estimate_id" value="{{ $estimates->first()->id }}"><x-operation-field name="note" label="توضیح تماس" kind="textarea" :maxlength="1000"/><p class="admin-hint">پرونده در انتظار پاسخ می‌ماند؛ تعمیر هنوز مجاز نیست.</p><button class="btn btn-secondary">ثبت تماس بی‌پاسخ</button></x-repair-operation>
</section>@endcan
@endif
