export interface DT {
    ajax: { dataSrc: (name: string) => any },
    columnDefs: (columns: (DataTables.ColumnSettings & { responsivePriority?: number })[]) => any
}
