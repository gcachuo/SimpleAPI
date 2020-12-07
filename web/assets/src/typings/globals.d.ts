interface ApiResponse<T = { [name: string]: object | number | string }> {
    code: number
    data: T
    message: string
}

interface JQuery {
    select2();

    modal();

    modal(event: ('hide'));

    modal(options: {
        backdrop?: boolean
    });
}
