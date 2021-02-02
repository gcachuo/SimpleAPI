interface ApiResponse<T = { [name: string]: object | number | string }> {
    code: number
    data: T
    message: string
}

interface JQuery {
    select2();

    select2(event: 'data', data: object[]);

    select2(options: {
        placeholder?: string,
        tags?: boolean,
        ajax?: { url: string, dataType: string, data?, processResults },
        maximumSelectionLength?: number,
        multiple?: boolean,
        width?: string,
        dropdownAutoWidth?: boolean,
    });

    modal();

    modal(event: ('hide'));

    modal(options: {
        backdrop?: boolean
    });
}
