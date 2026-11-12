const zone='Asia/Tehran';
const dayFormat=new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'});
const timeFormat=new Intl.DateTimeFormat('fa-IR-u-nu-arabext',{timeZone:zone,hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
const dateFormat=new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-arabext',{timeZone:zone,weekday:'long',month:'long',day:'numeric'});
const numericDateFormat=new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-arabext',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'});
export function tehranDay(date){const p=Object.fromEntries(dayFormat.formatToParts(date).map(p=>[p.type,p.value]));return `${p.year}-${p.month}-${p.day}`;}
export function tehranDateTime(date){
 if(Number.isNaN(date.getTime()))return 'زمان ثبت‌نشده';
 const {year,month,day}=Object.fromEntries(numericDateFormat.formatToParts(date).map(p=>[p.type,p.value]));
 return `${timeFormat.format(date)} | ${year}/${month}/${day}`;
}
export function persianDate(date,now=new Date()){
 if(Number.isNaN(date.getTime()))return {day:null,label:'زمان ثبت‌نشده',date:'',full:''};
 const day=tehranDay(date),today=tehranDay(now),yesterday=new Date(today+'T12:00:00Z');yesterday.setUTCDate(yesterday.getUTCDate()-1);
 const parts=Object.fromEntries(dateFormat.formatToParts(date).map(p=>[p.type,p.value]));const dateText=parts.weekday+'، '+parts.day+' '+parts.month;
 const full=tehranDateTime(date);
 const time=full.split(' | ')[0];
 return {day,time,date:day===today?'امروز':day===tehranDay(yesterday)?'دیروز':dateText,label:day===today?time:full,full};
}
// Existing polling/queue rendering calls this formatter again, including after
// foreground resume. No independent midnight polling or timer is required.
export function messageDate(m){
 if(m.accepted_at)return new Date(m.accepted_at);
 if(m._row)return new Date(m._presentation_at??m._row.created);
 const time=String(m.time??'').replace(' ','T');
 // Native chat history stored Tehran wall time, while exported ISO records
 // can already carry an offset. Appending a second offset invalidates them.
 return new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(time)?time:time+'+03:30');
}
