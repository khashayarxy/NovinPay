// Preserve native headers and controls; add mobile card labels without copying data.
export function mobileTable(table){
 table.classList.add('mobile-records');table.setAttribute('role','table');
 const labels=[...table.querySelectorAll('th')].map(x=>x.textContent);
 for(const row of table.rows){row.setAttribute('role','row');for(const [i,cell]of [...row.cells].entries()){
  cell.setAttribute('role',cell.tagName==='TH'?'columnheader':'cell');
  if(cell.tagName==='TD')cell.dataset.label=labels[i]||'';
 }}
 return table;
}
