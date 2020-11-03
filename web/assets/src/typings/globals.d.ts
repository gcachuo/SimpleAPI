interface ApiResponse<T = { [name: string]: object | number | string }> {
    code: number
    data: T
    message: string
}

interface JQuery {
    modal();

    modal(event: ('hide'));

    modal(options: {
        backdrop?: boolean
    });
}
