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

    const parseDate = (date) => {
        const [year, month, day] = date.split('-').map(Number);

        return new Date(year, month - 1, day);
    };

    const createClassBook = (element) => {
        const config = JSON.parse(element.dataset.classBook);
        const locale = document.documentElement.lang || 'de';

        const app = Vue.createApp({
            data() {
                return {
                    config: config,
                    week: null,
                    loading: false,
                    error: null,
                };
            },

            computed: {
                // Monday to Friday
                schoolDays() {
                    return this.week.days.filter((day) => day.weekday <= 5);
                },

                weekRange() {
                    const format = new Intl.DateTimeFormat(locale, {day: 'numeric', month: 'long', year: 'numeric'});

                    return format.formatRange(parseDate(this.week.start), parseDate(this.week.end));
                },
            },

            mounted() {
                this.loadWeek(null);
            },

            methods: {
                /**
                 * Send a request to the API. Resolves with the JSON data
                 * or rejects with an error code.
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

                    if (!response.ok) {
                        throw new Error(401 === response.status ? 'unauthorized' : 'general');
                    }

                    return response.json();
                },

                async loadWeek(date) {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await this.request(this.config.urls.week, {date: date});
                        this.week = data.week;
                    } catch (error) {
                        this.error = 'unauthorized' === error.message ? 'unauthorized' : 'general';
                    } finally {
                        this.loading = false;
                    }
                },

                formatWeekday(date) {
                    return new Intl.DateTimeFormat(locale, {weekday: 'long'}).format(parseDate(date));
                },

                formatDate(date) {
                    return new Intl.DateTimeFormat(locale, {day: 'numeric', month: 'numeric'}).format(parseDate(date));
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
