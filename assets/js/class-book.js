/*
 * This file is part of Contao Class Book.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license MIT
 * @link https://github.com/markocupic/contao-class-book
 */

/* global Vue */

(function () {
    'use strict';

    const TOKEN_HEADER = 'X-Class-Book-Token';

    // Error codes of the API and the label that is shown for them
    const ERROR_LABELS = {
        unauthorized: 'errorSession',
        validation: 'errorValidation',
        inUse: 'errorInUse',
        notFound: 'errorNotFound',
    };

    const parseDate = (date) => {
        const [year, month, day] = date.split('-').map(Number);

        return new Date(year, month - 1, day);
    };

    class ApiError extends Error {
        constructor(code, data) {
            super(code);
            this.code = code;
            this.data = data;
        }
    }

    const createClassBook = (element) => {
        const config = JSON.parse(element.dataset.classBook);
        const locale = document.documentElement.lang || 'de';

        const app = Vue.createApp({
            data() {
                return {
                    config: config,
                    labels: config.labels,
                    view: 'week',
                    loading: false,
                    error: null,

                    // Week view
                    week: null,

                    // Settings: {schema, data} of the master data, the selected
                    // resource, the open form and the record to be deleted
                    master: null,
                    resource: null,
                    form: null,
                    deleteId: null,
                };
            },

            computed: {
                errorMessage() {
                    return this.labels[ERROR_LABELS[this.error] || 'errorGeneral'];
                },

                // Monday to Friday
                schoolDays() {
                    return this.week.days.filter((day) => day.weekday <= 5);
                },

                weekRange() {
                    const format = new Intl.DateTimeFormat(locale, {day: 'numeric', month: 'long', year: 'numeric'});

                    return format.formatRange(parseDate(this.week.start), parseDate(this.week.end));
                },

                resourceNames() {
                    return Object.keys(this.master.schema);
                },

                fields() {
                    return this.master.schema[this.resource];
                },

                records() {
                    return this.master.data[this.resource];
                },
            },

            mounted() {
                this.loadWeek(null);
            },

            methods: {
                /**
                 * Send a request to the API. Resolves with the JSON data
                 * or rejects with an ApiError.
                 */
                async request(url, data) {
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            [TOKEN_HEADER]: this.config.token,
                        },
                        body: JSON.stringify(data),
                    });

                    let json = null;

                    try {
                        json = await response.json();
                    } catch (error) {
                        // No JSON, e.g. an error page
                    }

                    if (!response.ok || null === json) {
                        throw new ApiError(json && json.error ? json.error : 'general', json);
                    }

                    return json;
                },

                /**
                 * Run an API call, show the loading state and the error message.
                 * Returns the error or null.
                 */
                async run(callback) {
                    this.loading = true;
                    this.error = null;

                    try {
                        await callback();

                        return null;
                    } catch (error) {
                        this.error = error instanceof ApiError ? error.code : 'general';

                        return error;
                    } finally {
                        this.loading = false;
                    }
                },

                showWeek() {
                    this.view = 'week';
                    this.error = null;
                },

                showSettings() {
                    this.view = 'settings';
                    this.error = null;

                    if (!this.master) {
                        this.loadMasterData();
                    }
                },

                loadWeek(date) {
                    return this.run(async () => {
                        this.week = (await this.request(this.config.urls.week, {date: date})).week;
                    });
                },

                loadMasterData() {
                    return this.run(async () => {
                        const master = await this.request(this.config.urls.masterDataList, {});

                        if (!this.resource) {
                            this.resource = Object.keys(master.schema)[0];
                        }

                        this.master = master;
                    });
                },

                selectResource(name) {
                    this.resource = name;
                    this.form = null;
                    this.deleteId = null;
                    this.error = null;
                },

                openForm(record) {
                    const data = {};

                    Object.keys(this.fields).forEach((name) => {
                        const field = this.fields[name];
                        const fallback = 'color' === field.type ? '#1f6feb' : '';

                        data[name] = record ? record[name] : fallback;
                    });

                    this.form = {id: record ? record.id : null, data: data, errors: {}};
                    this.deleteId = null;
                    this.error = null;
                },

                async saveRecord() {
                    const payload = {resource: this.resource, id: this.form.id, data: this.form.data};
                    const error = await this.run(() => this.request(this.config.urls.masterDataSave, payload));

                    if (null === error) {
                        this.form = null;
                        await this.loadMasterData();
                    } else if ('validation' === error.code) {
                        this.form.errors = error.data.errors;
                    }
                },

                async deleteRecord(record) {
                    const payload = {resource: this.resource, id: record.id};
                    const error = await this.run(() => this.request(this.config.urls.masterDataDelete, payload));

                    this.deleteId = null;

                    if (null === error) {
                        this.form = null;
                        await this.loadMasterData();
                    }
                },

                errorLabel(name) {
                    return this.labels.errors[this.form.errors[name]];
                },

                inputType(field) {
                    return {integer: 'number', date: 'date', time: 'time', color: 'color'}[field.type] || 'text';
                },

                formatValue(value, field) {
                    if ('date' === field.type) {
                        return value ? this.formatDate(value) : '';
                    }

                    if ('reference' === field.type) {
                        const record = this.master.data[field.resource].find((item) => item.id === value);

                        return record ? record.title : '';
                    }

                    return value;
                },

                formatWeekday(date) {
                    return new Intl.DateTimeFormat(locale, {weekday: 'long'}).format(parseDate(date));
                },

                formatShortDate(date) {
                    return new Intl.DateTimeFormat(locale, {day: 'numeric', month: 'numeric'}).format(parseDate(date));
                },

                formatDate(date) {
                    return new Intl.DateTimeFormat(locale, {day: '2-digit', month: '2-digit', year: 'numeric'}).format(parseDate(date));
                },
            },
        });

        // Twig already uses the curly braces
        app.config.compilerOptions.delimiters = ['[[', ']]'];
        app.mount(element);
    };

    const init = () => document.querySelectorAll('[data-class-book]').forEach(createClassBook);

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
