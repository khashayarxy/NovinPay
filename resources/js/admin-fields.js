import {initSimpleRepair} from './admin-simple-repair.js';
import {initAdminCalendar} from './admin-calendar.js';
import {initAdminDigits} from './admin-digits.js';

export function initAdminFields(root) {
    initSimpleRepair();
    initAdminCalendar(root);
    initAdminDigits(root);
}
