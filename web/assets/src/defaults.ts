import AjaxSettings = DataTables.AjaxSettings;
import ColumnSettings = DataTables.ColumnSettings;
import ColumnDefsSettings = DataTables.ColumnDefsSettings;

import {DT} from "./typings/DataTables";

import $ from 'jquery';
import "bootstrap";
import * as toastr from "toastr";

import 'datatables.net';
import 'datatables.net-dt';
import 'datatables.net-buttons';

import '@fortawesome/fontawesome-free/js/fontawesome';
import '@fortawesome/fontawesome-free/js/solid';
import '@fortawesome/fontawesome-free/js/regular';
import '@fortawesome/fontawesome-free/js/brands';

interface ISettings {
    apiUrl: string,
    code?: string,
    dt?: ((DataTables.Settings & DT & { getColumns?: (columns: (ColumnSettings & { responsivePriority?: number })[]) => ColumnDefsSettings[] }))
}

export class Defaults {
    private static settings: ISettings;
    public static global: ISettings & { [name: string]: any } = Defaults.getSettings();
    private static $buttonHTML;

    public static init() {
        Defaults.ajaxSettings();

        Defaults.overwriteFormSubmit();

        Defaults.datatableSettings();

        Defaults.setupForms();

        Defaults.loadSelect2();

        $('.parent-module').off('click').on('click', (e) => {
            $(e.currentTarget).parent().toggleClass('active');
        });

        $("button").prop('disabled', false);
    }

    private static loadSelect2() {
        require('select2');
        if ($('.select2').length) {
            $.each($('.select2'), (i, element) => {
                $(element).select2({
                    width: 'resolve',
                    dropdownAutoWidth: true,
                    placeholder: $(element).data('placeholder')
                });
            });
        }
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
            $.fn.dataTable.Buttons.defaults.dom.button.className = 'btn';
            $.extend($.fn.dataTable.ext.classes, {
                sFilterInput: "form-control",
                sLengthSelect: "form-control",
                sPageButton: "btn btn-outline-info"
            });
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
                pageLength: 25,
                language: {
                    search: "",
                    searchPlaceholder: "Buscar:",
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

            this.settings.dt = <(DataTables.Settings & DT & { getColumns })>$.fn.dataTable.defaults;

            this.settings.dt.getColumns = (columns) => {
                (columns).map((column, index) => {
                    column['targets'] = index;
                    return column;
                });
                return <ColumnDefsSettings[]>columns;
            }
        }
    }

    private static setupForms() {
        $('[required]').prev('label').append((index, html) => {
            return html.indexOf('asteriskField') == -1 ? `<span class="asteriskField" style="color: #ff0000;" title="Campo requerido">&nbsp;*</span>` : "";
        });
    }

    private static ajaxSettings() {
        const ajaxSettings: AjaxSettings & { api: boolean } = {
            api: true,
            async: true,
            dataType: "json",
            beforeSend: function (jqXHR, settings: AjaxSettings & { api: boolean }) {
                if (settings.api) {
                    settings.url = Defaults.settings.apiUrl + settings.url;
                }
            },
            error: ({responseJSON, status: code}: JQuery.jqXHR) => {
                try {
                    let {message}: ApiResponse = responseJSON || {};
                    if (code >= 500) {
                        this.Alert('Ocurrió un error en la petición, por favor intente mas tarde.', 'error');
                        console.error(message, responseJSON);
                    } else if (code >= 400) {
                        if (code == 404) {
                            message = 'Not Found';
                        }
                        this.Alert(message, 'warning');
                        console.warn(message, responseJSON);
                    } else {
                        this.Alert(message, 'error');
                        console.error(responseJSON, code);
                    }
                } catch (e) {
                    this.Alert('Ocurrió un error en la petición, por favor intente mas tarde.', 'error');
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
        $(document).off('submit', 'form').on('submit', 'form', (e) => {
            const triggered = $(e.currentTarget).attr('triggered');
            const url = $(e.currentTarget).attr('uri');
            if (url && !triggered) {
                e.preventDefault();
                $(e.currentTarget).attr('triggered', 'true');

                const $button = $(`button[type='submit']`);
                this.$buttonHTML = $button.html();
                $button.prop('disabled', true).prepend('<i class="fa fa-spinner fa-spin"></i>' + ' ');

                const method = $(e.currentTarget).attr('method');
                const callback = $(e.currentTarget).attr('callback');
                const fileUpload = $(e.currentTarget).attr('fileUpload');
                const redirect = $(e.currentTarget).attr('redirect');

                if (!method) {
                    toastr.error('Missing property "method"');
                    return;
                }

                let valuePair: JQuery.NameValuePair[] | FormData = $(e.currentTarget).serializeArray();
                if (method.toUpperCase() === 'POST' && fileUpload) {
                    valuePair = new FormData(<HTMLFormElement>$(e.currentTarget).get(0));
                    $.ajaxSetup({
                        contentType: false,
                        processData: false,
                    });
                }

                let data: object = {};
                (valuePair as JQuery.NameValuePair[]).map(({name, value}) => {
                    if (name.includes('[]')) {
                        name = name.replace('[]', '');
                        data[name] = data[name] || [];
                        data[name].push(value);
                    } else {
                        data[name] = value;
                    }
                });

                $.ajax({
                    url, method, data: JSON.stringify(data), contentType: 'application/json'
                }).done((result) => {
                    if (window[callback]) {
                        window[callback](result);
                    } else if (callback) {
                        console.info(`Trigger: ${callback}`);
                    }
                    if (redirect) {
                        location.href = redirect;
                    }
                }).always(() => {
                    $(e.currentTarget).removeAttr('triggered');
                });
            }
        })
    }

    static closeModal() {
        $("#modal").on('transitionend', () => {
            $("#modal.hide").modal('hide').removeClass('hide');
        })
        $("#modal.show").addClass('hide');
    }

    public static async openModal(options: { title: string, url: string, animationClass?: string }) {
        $(document).on('click', "#modal .close", () => {
            this.closeModal();
        });
        if (!$('#modal').length) {
            $(`<div class="modal" id="modal">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button class="close"><i class="material-icons">arrow_back</i></button>
                                <h5 class="modal-title"></h5>
                            </div>
                            <div class="modal-body">Cargando...</div>
                        </div>
                    </div>
                </div>`).insertAfter('#view');
        } else {
            $('#modal .modal-body').text('Cargando...');
        }

        $('#modal .modal-title').text(options.title);
        $("#modal")
            .attr('class', 'modal')
            .addClass(options.animationClass || 'slideLeft')
            .modal({backdrop: false});

        const ajaxSettings: AjaxSettings & { api: boolean } = {
            api: false,
            url: options.url,
            dataType: "html",
        };

        try {
            const moduleClass = ajaxSettings.url.split('?');
            const response = await $.ajax(ajaxSettings);
            const html = ($('<div></div>').append(response)).find('#view').html();

            $('#modal .modal-body').empty().append($(`<div>${html}</div>`).addClass(moduleClass[0]));
        } catch (e) {
            if (e.status === 200) {
                console.log(e);
                return;
            }
            console.error('/' + ajaxSettings.url, e.status + ' ' + e.statusText);
            const html = ($('<div></div>').append(e.responseText)).find('#view').html();
            if (html) {
                $('#modal .modal-body').html(html);
            } else {
                $('#modal .modal-body').html(e.responseText);
            }
        }
    }

    static Alert(message, type = 'success') {
        toastr.clear();
        toastr[type](message);
    }

    static downloadFile(uri, name) {
        const url = Defaults.global.apiUrl + '/' + uri;

        const link = document.createElement("a");
        link.setAttribute('download', name);
        link.href = url;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }
}
