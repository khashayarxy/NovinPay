const zone='Asia/Tehran';
const dayFormat=new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'});
const timeFormat=new Intl.DateTimeFormat('fa-IR-u-nu-arabext',{timeZone:zone,hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
const dateFormat=new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-arabext',{timeZone:zone,weekday:'long',month:'long',day:'numeric'});
const fullFormat=new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-arabext',{timeZone:zone,year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'});
export function tehranDay(date){const p=Object.fromEntries(dayFormat.formatToParts(date).map(p=>[p.type,p.value]));return `${p.year}-${p.month}-${p.day}`;}
export function persianDate(date,now=new Date()){
 if(Number.isNaN(date.getTime()))return {day:null,label:'زمان ثبت‌نشده',date:'',full:''};
 const day=tehranDay(date),today=tehranDay(now),yesterday=new Date(today+'T12:00:00Z');yesterday.setUTCDate(yesterday.getUTCDate()-1);
 const parts=Object.fromEntries(dateFormat.formatToParts(date).map(p=>[p.type,p.value]));const dateText=parts.weekday+'، '+parts.day+' '+parts.month,time=timeFormat.format(date);
 return {day,time,date:day===today?'امروز':day===tehranDay(yesterday)?'دیروز':dateText,label:day===today?time:day===tehranDay(yesterday)?'دیروز، '+time:dateText+' - '+time,full:fullFormat.format(date)+' · '+zone};
}
// Existing polling/queue rendering calls this formatter again, including after
// foreground resume. No independent midnight polling or timer is required.
export function messageDate(m){return m.accepted_at?new Date(m.accepted_at):m._row?new Date(m._presentation_at??m._row.created):new Date(String(m.time).replace(' ','T')+'+03:30');}
