import AjaxSettings = DataTables.AjaxSettings;
import {DT} from "./typings/DataTables";
import * as $ from "jquery";
import * as toastr from "toastr";

export class Defaults {
    private static settings: { apiUrl: string, dt: (DataTables.Settings & DT) };
    public static global: { apiUrl, dt: (DataTables.Settings & DT) } = Defaults.getSettings();
    private static $buttonHTML;

    constructor() {

        Defaults.overwriteFormSubmit();

        Defaults.ajaxSettings();

        Defaults.datatableSettings();

    }

    private static getSettings() {
        try {
            this.settings = require('../../settings.dev.json');
        } catch (e) {
            this.settings = require('../../settings.json');
        }

        new Defaults();

        return this.settings;
    }

    private static datatableSettings(): void {
        if ($.fn.dataTable) {
            $.fn.dataTable.Buttons.defaults.dom.button.className = 'btn btn-sm';
            $.extend(true, $.fn.dataTable.defaults, {
                dom: 'Bfrtip',
                responsive: true,
                stateSave: true,
                order: [[0, 'asc']],
                buttons: [],
                ajax: {
                    dataSrc: (name) => {
                        return ({status, code, data, error}) => data[name]
                    }
                },
                columnDefs: (columns) => {
                    columns.map((column, index) => {
                        column['targets'] = index;
                        return column;
                    });
                    return columns;
                },
                pageLength: 25,
                language: {
                    search: "Buscar:",
                    emptyTable: "No hay registros que consultar",
                    lengthMenu: "Mostrar _MENU_ registros por pagina",
                    info: "Mostrando pagina _PAGE_ de _PAGES_",
                    infoEmpty: "Mostrando 0 a 0 de 0 registros",
                    loadingRecords: "Cargando...",
                    processing: "<i class='fa fa-spin fa-spinner'></i>",
                    paginate: {
                        first: "Primero",
                        last: "Ultimo",
                        next: "Siguiente",
                        previous: "Anterior"
                    },
                },
            } as AjaxSettings);

            this.settings.dt = <(DataTables.Settings & DT)>$.fn.dataTable.defaults;
        }
    }

    private static ajaxSettings() {
        const ajaxSettings: AjaxSettings & { api: boolean } = {
            api: true,
            async: true,
            beforeSend: function (jqXHR, settings: AjaxSettings & { api: boolean }) {
                if (settings.api) {
                    settings.url = Defaults.settings.apiUrl + settings.url;
                }
            },
            error: function ({responseJSON}) {
                toastr.clear();
                try {
                    const {code, data, message}: ApiResponse = responseJSON || {};
                    if (code >= 500) {
                        toastr.error('Ocurrió un error en la petición, por favor intente mas tarde.');
                        console.error(message, responseJSON);
                    } else if (code >= 400) {
                        toastr.warning(message);
                        console.warn(message, responseJSON);
                    }
                } catch (e) {
                    toastr.error('Ocurrió un error en la petición, por favor intente mas tarde.');
                    console.error(e, e);
                }
            },
            complete: function () {
                $(`button[type='submit']`).prop('disabled', false).html(Defaults.$buttonHTML);
            }
        };
        $.ajaxSetup(ajaxSettings);
    }

    private static overwriteFormSubmit() {
        $(document).off('submit').on('submit', 'form', (e) => {
            const url = $(e.currentTarget).attr('uri');
            if (url) {
                e.preventDefault();

                const $button = $(`button[type='submit']`);
                this.$buttonHTML = $button.html();
                $button.prop('disabled', true).prepend('<i class="fa fa-spinner fa-spin"></i>' + ' ');

                const method = $(e.currentTarget).attr('method');
                const callback = $(e.currentTarget).attr('callback');

                if (!method) {
                    toastr.error('Missing property "method"');
                    return;
                }

                let data: JQuery.NameValuePair[] | FormData = $(e.currentTarget).serializeArray();
                if (method.toUpperCase() === 'POST') {
                    data = new FormData(<HTMLFormElement>$(e.currentTarget).get(0));
                    $.ajaxSetup({
                        contentType: false,
                        processData: false,
                    });
                }

                $.ajax({
                    url, method, data,
                }).done((result) => {
                    if (window[callback]) {
                        window[callback](result);
                    } else if(callback) {
                        console.info(`Trigger: ${callback}`);
                    }
                });
            }
        })
    }
}
