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
    const THEME_KEY = 'cb-theme';
    const WEEKEND_KEY = 'cb-weekend';

    /**
     * The theme the user has chosen, else the one of the operating system.
     * The storage can be unavailable, e.g. in private mode.
     */
    const getInitialTheme = () => {
        try {
            const stored = window.localStorage.getItem(THEME_KEY);

            if ('light' === stored || 'dark' === stored) {
                return stored;
            }
        } catch (error) {
            // No storage
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };

    // Whether the week view shows Saturday and Sunday (the choice of the user, off by default)
    const getInitialWeekend = () => {
        try {
            return '1' === window.localStorage.getItem(WEEKEND_KEY);
        } catch (error) {
            return false;
        }
    };

    // Error codes of the API and the label that is shown for them
    const ERROR_LABELS = {
        unauthorized: 'errorSession',
        validation: 'errorValidation',
        inUse: 'errorInUse',
        notFound: 'errorNotFound',
        reauthenticate: 'errorReauthenticate',
    };

    const parseDate = (date) => {
        const [year, month, day] = date.split('-').map(Number);

        return new Date(year, month - 1, day);
    };

    const formatIsoDate = (date) => {
        const pad = (value) => String(value).padStart(2, '0');

        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    };

    // The number of days from one date (YYYY-MM-DD) to another
    const daysBetween = (from, to) => Math.round((parseDate(to) - parseDate(from)) / 86400000);

    // 2024-01-01 was a Monday
    const weekdayDate = (weekday) => new Date(2024, 0, weekday);

    /**
     * The data that is needed to show a lesson. A cell of the week view shows the
     * short title of the subject only, the other details are in the tooltip.
     * The day view shows the title and the details.
     */
    const presentLesson = (lesson, subjects, groups, labels) => {
        const subject = subjects[lesson.subject] || {};
        const group = groups[lesson.learningGroup] || {};
        const tooltip = [subject.title || '', labels.fields.learningGroup + ': ' + (group.title || '')];

        if (lesson.room) {
            tooltip.push(labels.fields.room + ': ' + lesson.room);
        }

        return {
            id: lesson.id,
            subject: lesson.subject,
            learningGroup: lesson.learningGroup,
            shortTitle: subject.shortTitle || '',
            title: subject.title || '',
            details: [group.title || '', lesson.room].filter((value) => '' !== value).join(' \u00b7 '),
            room: lesson.room,
            tooltip: tooltip.join('\n'),
            style: {'--cb-lesson-color': subject.color || '#888888'},
        };
    };

    const byId = (records) => Object.fromEntries(records.map((record) => [record.id, record]));

    const hexToRgb = (hex) => [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16));

    /**
     * The colour of a palette that is closest to a colour (#rrggbb)
     */
    const nearestColor = (color, palette) => {
        const [r, g, b] = hexToRgb(color);
        const distance = (other) => {
            const [r2, g2, b2] = hexToRgb(other);

            return (r - r2) ** 2 + (g - g2) ** 2 + (b - b2) ** 2;
        };

        return palette.reduce((best, other) => (distance(other) < distance(best) ? other : best), palette[0]);
    };

    /**
     * Shuffle a copy of a list (Fisher-Yates)
     */
    const shuffle = (list) => {
        const result = [...list];

        for (let index = result.length - 1; index > 0; index--) {
            const other = Math.floor(Math.random() * (index + 1));

            [result[index], result[other]] = [result[other], result[index]];
        }

        return result;
    };

    /**
     * Split a list into random groups whose sizes differ by one at most.
     * Mode "count": that many groups. Mode "size": groups of at most that size.
     */
    const makeRandomGroups = (list, mode, value) => {
        const number = Math.max(1, Math.floor(value) || 1);
        const count = Math.min(list.length, 'size' === mode ? Math.ceil(list.length / number) : number);
        const groups = Array.from({length: count}, () => []);

        shuffle(list).forEach((item, index) => groups[index % count].push(item));

        return groups;
    };

    // -----------------------------------------------------------------------
    // Word export without a library: a .docx file is a ZIP archive of XML files
    // -----------------------------------------------------------------------

    const CRC_TABLE = Array.from({length: 256}, (unused, number) => {
        let value = number;

        for (let bit = 0; bit < 8; bit++) {
            value = value & 1 ? 0xEDB88320 ^ (value >>> 1) : value >>> 1;
        }

        return value >>> 0;
    });

    const crc32 = (bytes) => {
        let crc = 0xFFFFFFFF;

        for (let index = 0; index < bytes.length; index++) {
            crc = CRC_TABLE[(crc ^ bytes[index]) & 0xFF] ^ (crc >>> 8);
        }

        return (crc ^ 0xFFFFFFFF) >>> 0;
    };

    /**
     * Build a ZIP archive (stored, not compressed) from {name: text}
     */
    const createZip = (files) => {
        const encoder = new TextEncoder();
        const parts = [];
        const directory = [];
        let offset = 0;

        const header = (signature, fields) => {
            const view = new DataView(new ArrayBuffer(4 + fields.reduce((sum, field) => sum + field[0], 0)));
            let position = 4;

            view.setUint32(0, signature, true);

            fields.forEach(([size, value]) => {
                if (2 === size) {
                    view.setUint16(position, value, true);
                } else {
                    view.setUint32(position, value, true);
                }

                position += size;
            });

            return new Uint8Array(view.buffer);
        };

        Object.keys(files).forEach((name) => {
            const nameBytes = encoder.encode(name);
            const data = encoder.encode(files[name]);
            const crc = crc32(data);

            // version, flags (UTF-8 names), method (stored), time, date (1980-01-01), crc, sizes, name length, extra length
            const common = [[2, 20], [2, 0x0800], [2, 0], [2, 0], [2, 0x0021], [4, crc], [4, data.length], [4, data.length], [2, nameBytes.length], [2, 0]];
            const local = header(0x04034B50, common);

            // version made by, then the common fields, comment length, disk, attributes, offset of the local header
            directory.push(header(0x02014B50, [[2, 20], ...common, [2, 0], [2, 0], [2, 0], [4, 0], [4, offset]]), nameBytes);
            parts.push(local, nameBytes, data);
            offset += local.length + nameBytes.length + data.length;
        });

        const directorySize = directory.reduce((sum, part) => sum + part.length, 0);
        const count = Object.keys(files).length;
        const end = header(0x06054B50, [[2, 0], [2, 0], [2, count], [2, count], [4, directorySize], [4, offset], [2, 0]]);

        return new Blob([...parts, ...directory, end], {type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'});
    };

    const escapeXml = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    /**
     * A Word document with a title, a subtitle and the groups in a table of three columns.
     * groups: [{title, members: [name]}]
     */
    const createGroupsDocx = (title, subtitle, groups) => {
        const paragraph = (text, format, spacing) => '<w:p><w:pPr><w:spacing w:before="0" w:after="' + spacing + '"/></w:pPr><w:r><w:rPr>' + format + '</w:rPr><w:t xml:space="preserve">' + escapeXml(text) + '</w:t></w:r></w:p>';
        const columns = 3;
        const width = Math.floor(9638 / columns);
        let rows = '';

        for (let index = 0; index < groups.length; index += columns) {
            let cells = '';

            for (let column = 0; column < columns; column++) {
                const group = groups[index + column];

                // A cell needs at least one paragraph
                const content = group
                    ? paragraph(group.title, '<w:b/><w:sz w:val="26"/>', 80) + group.members.map((name) => paragraph(name, '<w:sz w:val="22"/>', 40)).join('')
                    : '<w:p/>';

                cells += '<w:tc><w:tcPr><w:tcW w:w="' + width + '" w:type="dxa"/><w:tcMar><w:bottom w:w="280" w:type="dxa"/></w:tcMar></w:tcPr>' + content + '</w:tc>';
            }

            rows += '<w:tr><w:trPr><w:cantSplit/></w:trPr>' + cells + '</w:tr>';
        }

        const body = paragraph(title, '<w:b/><w:sz w:val="36"/>', 60)
            + paragraph(subtitle, '<w:color w:val="5B6470"/><w:sz w:val="22"/>', 360)
            + '<w:tbl><w:tblPr><w:tblW w:w="9638" w:type="dxa"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>' + '<w:gridCol w:w="' + width + '"/>'.repeat(columns) + '</w:tblGrid>' + rows + '</w:tbl>'
            + '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>';

        const declaration = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        return createZip({
            '[Content_Types].xml': declaration + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels': declaration + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml': declaration + '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' + body + '</w:body></w:document>',
        });
    };

    const downloadBlob = (blob, filename) => {
        const link = document.createElement('a');

        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();

        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    };

    // -----------------------------------------------------------------------
    // Classrooms and seating plans
    // -----------------------------------------------------------------------

    // A room is a grid of cells. A seat takes 4 x 3 cells.
    const ROOM = {width: 40, height: 26};
    const SEAT = {width: 4, height: 3};

    // Size in cells, seats (column and row) and the pairs of seats that are next to each other
    const DESK_TYPES = {
        teacher: {width: 6, height: 3, seats: [], pairs: []},
        single: {width: 4, height: 3, seats: [[0, 0]], pairs: []},
        double: {width: 8, height: 3, seats: [[0, 0], [1, 0]], pairs: [[0, 1]]},
        quad: {width: 8, height: 6, seats: [[0, 0], [1, 0], [0, 1], [1, 1]], pairs: [[0, 1], [2, 3]]},
    };

    // The area of a desk in cells. A desk is upright (r = 0) or turned by 90 degrees.
    const deskBox = (desk) => {
        const type = DESK_TYPES[desk.type];

        return 90 === desk.r
            ? {x: desk.x, y: desk.y, width: type.height, height: type.width}
            : {x: desk.x, y: desk.y, width: type.width, height: type.height};
    };

    /**
     * The seats of a desk: [{key, x, y, width, height}] in cells. The key is "<desk>:<number>".
     */
    const deskSeats = (desk) => DESK_TYPES[desk.type].seats.map(([column, row], index) => {
        const x = column * SEAT.width;
        const y = row * SEAT.height;
        const key = desk.id + ':' + index;

        return 90 === desk.r
            ? {key: key, x: desk.x + DESK_TYPES[desk.type].height - y - SEAT.height, y: desk.y + x, width: SEAT.height, height: SEAT.width}
            : {key: key, x: desk.x + x, y: desk.y + y, width: SEAT.width, height: SEAT.height};
    });

    /**
     * Whether a desk is inside the room and does not overlap another desk
     */
    const deskFits = (desk, desks) => {
        const box = deskBox(desk);

        if (box.x < 0 || box.y < 0 || box.x + box.width > ROOM.width || box.y + box.height > ROOM.height) {
            return false;
        }

        return desks.every((other) => {
            const otherBox = deskBox(other);

            return other.id === desk.id
                || box.x >= otherBox.x + otherBox.width || otherBox.x >= box.x + box.width
                || box.y >= otherBox.y + otherBox.height || otherBox.y >= box.y + box.height;
        });
    };

    /**
     * The first free position for a desk, row by row, or null if the room is full
     */
    const findDeskPosition = (desk, desks) => {
        for (let y = 1; y < ROOM.height; y++) {
            for (let x = 1; x < ROOM.width; x++) {
                if (deskFits({...desk, x: x, y: y}, desks)) {
                    return {x: x, y: y};
                }
            }
        }

        return null;
    };

    /**
     * The desks of a stored layout ({desks: [{id, type, x, y, r}]}). Invalid desks are dropped.
     */
    const parseLayout = (json) => {
        let layout = null;

        try {
            layout = JSON.parse(json || '{}');
        } catch (error) {
            // An empty room
        }

        const desks = [];

        (layout && Array.isArray(layout.desks) ? layout.desks : []).forEach((item) => {
            const desk = {id: String(item.id), type: item.type, x: Number(item.x), y: Number(item.y), r: 90 === item.r ? 90 : 0};

            if (/^[a-z0-9]{1,12}$/.test(desk.id) && DESK_TYPES[desk.type] && Number.isInteger(desk.x) && Number.isInteger(desk.y) && !desks.some((other) => other.id === desk.id) && deskFits(desk, desks)) {
                desks.push(desk);
            }
        });

        return desks;
    };

    /**
     * A stored seating plan ({seats: {seat: student}, blocked: [seat], fixed: [seat]}),
     * reduced to the seats of the room and the students of the group.
     */
    const parseSeating = (json, desks, students) => {
        let seating = null;

        try {
            seating = JSON.parse(json || '{}');
        } catch (error) {
            // No seating plan
        }

        const keys = desks.flatMap((desk) => deskSeats(desk).map((seat) => seat.key));
        const ids = students.map((student) => student.id);
        const result = {seats: {}, blocked: {}, fixed: {}};
        const list = (value) => (Array.isArray(value) ? value : []);

        list(seating && seating.blocked).forEach((key) => {
            if (keys.includes(key)) {
                result.blocked[key] = true;
            }
        });

        Object.entries(seating && seating.seats && 'object' === typeof seating.seats ? seating.seats : {}).forEach(([key, id]) => {
            if (keys.includes(key) && !result.blocked[key] && ids.includes(id) && !Object.values(result.seats).includes(id)) {
                result.seats[key] = id;
            }
        });

        list(seating && seating.fixed).forEach((key) => {
            if (undefined !== result.seats[key]) {
                result.fixed[key] = true;
            }
        });

        return result;
    };

    /**
     * Draw a seating plan: {seat: student id}. The given seats (set by hand) are kept, the other
     * students are placed at random. Blocked seats and teacher desks stay empty.
     * With "mix" a girl sits next to a boy wherever that is possible: first next to those who
     * are already seated, then on as many pairs of free neighbouring seats as possible.
     * Students without a seat (more students than seats) are not part of the result.
     */
    const drawSeating = (desks, blocked, kept, students, mix) => {
        const seats = {...kept};
        const placed = Object.fromEntries(Object.values(kept).map((id) => [id, true]));
        const genders = Object.fromEntries(students.map((student) => [student.id, student.gender]));
        const isFree = (key) => !blocked[key] && undefined === seats[key];
        const genderAt = (key) => genders[seats[key]] || '';

        const place = (key, student) => {
            seats[key] = student.id;
            placed[student.id] = true;
        };

        if (mix) {
            const waiting = {
                f: shuffle(students.filter((student) => 'f' === student.gender && !placed[student.id])),
                m: shuffle(students.filter((student) => 'm' === student.gender && !placed[student.id])),
            };

            const other = (gender) => ('f' === gender ? 'm' : 'f');

            // The pairs of neighbouring seats, with the other pair of the same desk (desk for four)
            const pairs = shuffle(desks.flatMap((desk) => {
                const deskPairs = DESK_TYPES[desk.type].pairs.map((pair) => pair.map((index) => desk.id + ':' + index));

                return deskPairs.map((keys, index) => ({keys: keys, opposite: deskPairs[1 - index] || null}));
            }));

            // Next to someone who is seated already
            pairs.forEach((pair) => {
                pair.keys.forEach((key, index) => {
                    const gender = genderAt(pair.keys[1 - index]);

                    if (isFree(key) && '' !== gender && waiting[other(gender)].length > 0) {
                        place(key, waiting[other(gender)].pop());
                    }
                });
            });

            // Free pairs. At a desk for four the second row is the other way round,
            // so that the two who sit opposite each other are mixed too
            pairs.forEach((pair) => {
                if (!pair.keys.every(isFree) || 0 === waiting.f.length || 0 === waiting.m.length) {
                    return;
                }

                const opposite = pair.opposite ? pair.opposite.map(genderAt) : ['', ''];
                const first = '' !== opposite[0] ? other(opposite[0]) : ('' !== opposite[1] ? opposite[1] : (Math.random() < .5 ? 'f' : 'm'));

                place(pair.keys[0], waiting[first].pop());
                place(pair.keys[1], waiting[other(first)].pop());
            });
        }

        const free = shuffle(desks.flatMap((desk) => deskSeats(desk).map((seat) => seat.key)).filter(isFree));

        shuffle(students.filter((student) => !placed[student.id])).slice(0, free.length).forEach((student, index) => place(free[index], student));

        return seats;
    };

    // Position and size in percent of the room or of another area
    const areaStyle = (box, area) => ({
        left: (box.x - area.x) / area.width * 100 + '%',
        top: (box.y - area.y) / area.height * 100 + '%',
        width: box.width / area.width * 100 + '%',
        height: box.height / area.height * 100 + '%',
    });

    const ROOM_AREA = {x: 0, y: 0, width: ROOM.width, height: ROOM.height};

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

                    // 'light' or 'dark', set as data-theme on the root element
                    theme: getInitialTheme(),

                    // Whether the user menu (security, log out) is open
                    userMenu: false,

                    // Week view: with Saturday and Sunday
                    weekend: getInitialWeekend(),

                    // Week view: the week and its timetable
                    week: null,
                    timetable: null,
                    entries: [],
                    birthdays: [],

                    // Day view: the displayed day (YYYY-MM-DD), always a day of the loaded week
                    day: null,

                    // Box "important dates": {entries, birthdays}
                    important: null,

                    // Entries: the number of tasks of previous days that are not done,
                    // their list (if it is shown) and the open form
                    openCount: 0,
                    openEntries: null,
                    entryForm: null,

                    // Drag and drop of an entry: {entry, target}. The target is the slot under
                    // the pointer and, if the pointer is over an entry, that entry and the side
                    drag: null,

                    // Settings: {schema, data} of the master data, the selected
                    // resource, the open form and the record to be deleted
                    master: null,
                    resource: null,
                    form: null,
                    deleteId: null,

                    // Random groups: the class or learning group, the mode ("count" or "size")
                    // with its value, the deselected students by id and the groups
                    groupTool: {groupId: '', mode: 'count', value: 4, absent: {}, result: null},

                    // Security (settings): the state of the two-factor authentication, the running
                    // setup ({secret, image}), the entered code and the new backup codes
                    security: {status: null, setup: null, code: '', invalid: false, codes: null, confirmDisable: false},

                    // Classroom editor (settings): the desks of the open classroom, the selected
                    // desk and the desk that is being moved with the pointer
                    roomEditor: null,

                    // Seating plan: the class or learning group, the seats ({seat: student id}),
                    // the blocked seats, the fixed seats (set by hand, kept when the plan is drawn),
                    // the picked student (to move him or her with two clicks) and whether the plan
                    // differs from the stored one
                    seatTool: {groupId: '', mix: true, seats: {}, blocked: {}, fixed: {}, picked: null, dragged: null, dirty: false, saved: false},

                    // Timetable editor: the selected school year and the open lesson
                    timetableYear: null,
                    lessonForm: null,
                };
            },

            computed: {
                // The label of the theme button: the theme it switches to
                themeLabel() {
                    return 'dark' === this.theme ? this.labels.themeLight : this.labels.themeDark;
                },

                errorMessage() {
                    return this.labels[ERROR_LABELS[this.error] || 'errorGeneral'];
                },

                // Monday to Friday, with the holiday of the day. Saturday and Sunday
                // if the user shows them or if there are lessons on that day
                weekDays() {
                    return this.week.days
                        .filter((day) => this.weekend || day.weekday <= 5 || this.timetable.days[day.date].lessons.length > 0)
                        .map((day) => ({...day, holiday: this.timetable.days[day.date].holiday}));
                },

                // The number of entries and birthdays on the days of the weekend that are hidden
                weekendCount() {
                    const dates = this.week.days.filter((day) => !this.weekDays.some((shown) => shown.date === day.date)).map((day) => day.date);

                    return this.entries.filter((entry) => dates.includes(entry.entryDate)).length
                        + this.birthdays.filter((birthday) => dates.includes(birthday.date)).length;
                },

                // One row per period with one cell per day
                weekGrid() {
                    return this.timetable.periods.map((period) => ({
                        period: period,
                        cells: this.weekDays.map((day) => {
                            const lesson = this.timetable.days[day.date].lessons.find((item) => item.period === period.id);

                            return {
                                day: day,
                                lesson: lesson ? presentLesson(lesson, this.timetable.subjects, this.timetable.groups, this.labels) : null,
                                entries: this.entries.filter((entry) => entry.entryDate === day.date && entry.period === period.id),
                            };
                        }),
                    }));
                },

                // The entries of the whole day, sorted by time
                dayRow() {
                    return this.weekDays.map((day) => ({
                        day: day,
                        entries: this.entries.filter((entry) => entry.entryDate === day.date && 0 === entry.period),
                        birthdays: this.birthdays.filter((birthday) => birthday.date === day.date),
                    }));
                },

                // Day view: the whole day and all periods, also those without a lesson
                dayData() {
                    const day = this.week.days.find((item) => item.date === this.day);

                    if (!day) {
                        return null;
                    }

                    const timetable = this.timetable.days[day.date];
                    const entries = this.entries.filter((entry) => entry.entryDate === day.date);

                    return {
                        holiday: timetable.holiday,
                        birthdays: this.birthdays.filter((birthday) => birthday.date === day.date),
                        wholeDay: {entries: entries.filter((entry) => 0 === entry.period)},
                        periods: this.timetable.periods.map((period) => {
                            const lesson = timetable.lessons.find((item) => item.period === period.id);
                            const periodEntries = entries.filter((entry) => entry.period === period.id);

                            return {
                                key: period.id,
                                periodId: period.id,
                                title: String(period.number),
                                time: period.startTime + '\u2013' + period.endTime,
                                lesson: lesson ? presentLesson(lesson, this.timetable.subjects, this.timetable.groups, this.labels) : null,
                                entries: periodEntries,
                                empty: !lesson && 0 === periodEntries.length,
                            };
                        }),
                    };
                },

                // The important entries and the birthdays of the next days, sorted by date
                importantItems() {
                    const today = this.week.today;

                    const items = this.important.entries.map((entry) => ({
                        key: 'entry-' + entry.id,
                        date: entry.entryDate,
                        entry: entry,
                        text: (entry.entryTime ? entry.entryTime + ' ' : '') + entry.content,
                    }));

                    this.important.birthdays.forEach((birthday) => {
                        items.push({key: 'birthday-' + birthday.student, date: birthday.date, entry: null, text: this.birthdayText(birthday)});
                    });

                    return items
                        .map((item) => ({...item, days: daysBetween(today, item.date), when: this.formatRelativeDate(item.date)}))
                        .sort((a, b) => a.date.localeCompare(b.date))
                    ;
                },

                // The reminders in the order of the language file
                reminderOptions() {
                    return Object.keys(this.labels.options.reminder);
                },

                // In the order of the language file
                entryTypes() {
                    return Object.keys(this.labels.options.type);
                },

                weekHasLessons() {
                    return this.entries.length > 0 || this.weekDays.some((day) => day.holiday || this.timetable.days[day.date].lessons.length > 0);
                },

                weekRange() {
                    const format = new Intl.DateTimeFormat(locale, {day: 'numeric', month: 'long', year: 'numeric'});

                    return format.formatRange(parseDate(this.week.start), parseDate(this.week.end));
                },

                // The students of the selected class (home class) or learning group
                groupStudents() {
                    const group = this.master.data.group.find((item) => item.id === this.groupTool.groupId);

                    if (!group) {
                        return [];
                    }

                    return this.studentsOf(group);
                },

                presentStudents() {
                    return this.master ? this.groupStudents.filter((student) => !this.groupTool.absent[student.id]) : [];
                },

                // E.g. "18 von 20"
                presentCountLabel() {
                    return this.labels.groupsOf.replace('%d', this.presentStudents.length).replace('%d', this.groupStudents.length);
                },

                // The title and the date of the group sheet, e.g. "Gruppen 9.2" and "20.11.2026"
                groupSheet() {
                    const group = this.master.data.group.find((item) => item.id === this.groupTool.groupId);

                    return {
                        title: this.labels.viewGroups + ' ' + (group ? group.title : ''),
                        date: this.formatDate(this.week ? this.week.today : formatIsoDate(new Date())),
                    };
                },

                // Classroom editor: the desks with their position and their seats
                editorDesks() {
                    return this.roomEditor.desks.map((desk) => this.presentDesk(desk, () => ({})));
                },

                deskTypes() {
                    return Object.keys(DESK_TYPES);
                },

                seatGroup() {
                    return this.master.data.group.find((item) => item.id === this.seatTool.groupId) || null;
                },

                seatRoom() {
                    return this.seatGroup ? this.master.data.classroom.find((item) => item.id === this.seatGroup.classroom) || null : null;
                },

                seatLayout() {
                    return this.seatRoom ? parseLayout(this.seatRoom.layout) : [];
                },

                seatStudents() {
                    return this.seatGroup ? this.studentsOf(this.seatGroup) : [];
                },

                // Seating plan: the desks with the student or the lock of every seat
                seatDesks() {
                    const students = byId(this.seatStudents);

                    return this.seatLayout.map((desk) => this.presentDesk(desk, (key) => ({
                        student: students[this.seatTool.seats[key]] || null,
                        blocked: true === this.seatTool.blocked[key],
                        fixed: true === this.seatTool.fixed[key],
                    })));
                },

                // The students without a seat
                seatUnseated() {
                    const seated = Object.values(this.seatTool.seats);

                    return this.seatStudents.filter((student) => !seated.includes(student.id));
                },

                // The number of seats that can be used
                seatCount() {
                    return this.seatDesks.reduce((sum, desk) => sum + desk.seats.filter((seat) => !seat.blocked).length, 0);
                },

                // E.g. "22 Plätze für 20 Schülerinnen und Schüler"
                seatCountLabel() {
                    return this.labels.seatingCount.replace('%d', this.seatCount).replace('%d', this.seatStudents.length);
                },

                // The number of students whose gender is not known
                seatWithoutGender() {
                    return this.seatStudents.filter((student) => '' === student.gender).length;
                },

                seatSheet() {
                    return {
                        title: this.labels.viewSeating + ' ' + (this.seatGroup ? this.seatGroup.title : ''),
                        room: this.seatRoom ? this.seatRoom.title : '',
                        date: this.formatDate(this.week ? this.week.today : formatIsoDate(new Date())),
                    };
                },

                // The fields of the generic lists and forms
                visibleFields() {
                    return Object.fromEntries(Object.entries(this.fields).filter(([, field]) => !field.hidden));
                },

                // The lessons have an editor of their own
                resourceNames() {
                    return Object.keys(this.master.schema).filter((name) => 'lesson' !== name);
                },

                // Monday to Friday. Saturday and Sunday if they are shown or have lessons
                editorWeekdays() {
                    const lessons = this.master.data.lesson.filter((lesson) => lesson.schoolYear === this.timetableYear);

                    return [1, 2, 3, 4, 5, 6, 7].filter((weekday) => weekday <= 5 || this.weekend || lessons.some((lesson) => lesson.weekday === weekday));
                },

                // One row per period with one cell per weekday
                editorGrid() {
                    const subjects = byId(this.master.data.subject);
                    const groups = byId(this.master.data.group);
                    const lessons = this.master.data.lesson.filter((lesson) => lesson.schoolYear === this.timetableYear);
                    const form = this.lessonForm;

                    return this.master.data.period.map((period) => ({
                        period: period,
                        cells: this.editorWeekdays.map((weekday) => {
                            const lesson = lessons.find((item) => item.weekday === weekday && item.period === period.id);

                            return {
                                weekday: weekday,
                                lesson: lesson ? presentLesson(lesson, subjects, groups, this.labels) : null,
                                active: null !== form && form.weekday === weekday && form.period.id === period.id,
                            };
                        }),
                    }));
                },

                // The classes and learning groups of the selected school year
                timetableGroups() {
                    return this.master.data.group.filter((group) => group.schoolYear === this.timetableYear);
                },

                fields() {
                    return this.master.schema[this.resource] || {};
                },

                records() {
                    return this.master.data[this.resource] || [];
                },

                // E.g. "Noch 8 Backup-Codes"
                backupCodesCount() {
                    return this.labels.backupCodesCount.replace('%d', this.security.status.backupCodes);
                },
            },

            created() {
                // Vue does not manage the attributes of the root element
                element.dataset.theme = this.theme;
            },

            mounted() {
                // The day view is the better start on a phone
                if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) {
                    this.view = 'day';
                }

                // A click outside or the escape key closes the user menu
                document.addEventListener('click', (event) => {
                    if (!event.target.closest('.cb-user')) {
                        this.userMenu = false;
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if ('Escape' === event.key) {
                        this.userMenu = false;
                    }
                });

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

                toggleTheme() {
                    this.theme = 'dark' === this.theme ? 'light' : 'dark';
                    element.dataset.theme = this.theme;

                    try {
                        window.localStorage.setItem(THEME_KEY, this.theme);
                    } catch (error) {
                        // No storage: the choice is valid until the page is reloaded
                    }
                },

                toggleWeekend() {
                    this.weekend = !this.weekend;

                    try {
                        window.localStorage.setItem(WEEKEND_KEY, this.weekend ? '1' : '0');
                    } catch (error) {
                        // No storage: the choice is valid until the page is reloaded
                    }
                },

                showWeek() {
                    this.view = 'week';

                    // The timetable may have been changed in the settings
                    this.loadWeek(this.week ? this.week.start : null);
                },

                showDay() {
                    this.view = 'day';
                    this.loadWeek(this.day);
                },

                /**
                 * Show a day in the day view. Loads its week if necessary.
                 */
                loadDay(date) {
                    this.view = 'day';
                    this.day = date;

                    if (!this.week.days.some((day) => day.date === date)) {
                        return this.loadWeek(date);
                    }

                    return Promise.resolve();
                },

                /**
                 * Go a number of weeks back or forward in the week view
                 */
                shiftWeeks(weeks) {
                    const date = parseDate(this.week.start);

                    date.setDate(date.getDate() + 7 * weeks);

                    return this.loadWeek(formatIsoDate(date));
                },

                /**
                 * Show the week or the day of a date (YYYY-MM-DD) that was picked in the date field
                 */
                jumpToDate(date) {
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                        return Promise.resolve();
                    }

                    return 'day' === this.view ? this.loadDay(date) : this.loadWeek(date);
                },

                shiftDay(days) {
                    const date = parseDate(this.day);

                    date.setDate(date.getDate() + days);

                    return this.loadDay(formatIsoDate(date));
                },

                showGroups() {
                    this.view = 'groups';
                    this.error = null;

                    if (!this.master) {
                        this.loadMasterData();
                    }
                },

                resetGroups() {
                    this.groupTool.absent = {};
                    this.groupTool.result = null;
                },

                togglePresent(student) {
                    this.groupTool.absent = {...this.groupTool.absent, [student.id]: !this.groupTool.absent[student.id]};
                    this.groupTool.result = null;
                },

                setAllPresent(present) {
                    this.groupTool.absent = present ? {} : Object.fromEntries(this.groupStudents.map((student) => [student.id, true]));
                    this.groupTool.result = null;
                },

                makeGroups() {
                    const byName = (a, b) => (a.lastname + ' ' + a.firstname).localeCompare(b.lastname + ' ' + b.firstname, locale);

                    this.groupTool.result = makeRandomGroups(this.presentStudents, this.groupTool.mode, this.groupTool.value).map((members) => members.sort(byName));
                },

                exportGroupsDocx() {
                    const groups = this.groupTool.result.map((members, index) => ({
                        title: this.labels.groupsGroup + ' ' + (index + 1),
                        members: members.map((student) => student.firstname + ' ' + student.lastname),
                    }));

                    const filename = (this.groupSheet.title + ' ' + (this.week ? this.week.today : formatIsoDate(new Date()))).replace(/[^\p{L}\p{N}.-]+/gu, '-') + '.docx';

                    downloadBlob(createGroupsDocx(this.groupSheet.title, this.groupSheet.date, groups), filename);
                },

                /**
                 * Print the group sheet only. The browser can save the print as a PDF file.
                 */
                printGroups() {
                    const root = document.documentElement;

                    root.classList.add('cb-print-groups');
                    window.addEventListener('afterprint', () => root.classList.remove('cb-print-groups'), {once: true});
                    window.print();
                },

                // ---------------------------------------------------------------
                // Classrooms and seating plans
                // ---------------------------------------------------------------

                // The students of a class (home class) or of a learning group
                studentsOf(group) {
                    return this.master.data.student.filter((student) => ('class' === group.kind ? student.homeClass === group.id : student.learningGroups.includes(group.id)));
                },

                /**
                 * A desk with its position and its seats for the template.
                 * The callback returns the content of a seat by its key.
                 */
                presentDesk(desk, seatContent) {
                    const box = deskBox(desk);

                    return {
                        ...desk,
                        style: areaStyle(box, ROOM_AREA),
                        seats: deskSeats(desk).map((seat) => ({key: seat.key, style: areaStyle(seat, box), ...seatContent(seat.key)})),
                    };
                },

                deskLabel(type) {
                    return this.labels.options.desk[type];
                },

                // The cell under the pointer
                roomCell(event) {
                    const rect = this.$refs.room.getBoundingClientRect();

                    return {x: (event.clientX - rect.left) / rect.width * ROOM.width, y: (event.clientY - rect.top) / rect.height * ROOM.height};
                },

                /**
                 * Add a desk: at the given cell (its centre) if there is room, else at the first free position
                 */
                addDesk(type, cell) {
                    const desks = this.roomEditor.desks;
                    const desk = {id: Math.random().toString(36).slice(2, 10), type: type, x: 0, y: 0, r: 0};
                    const box = deskBox(desk);

                    if (cell) {
                        desk.x = Math.min(ROOM.width - box.width, Math.max(0, Math.round(cell.x - box.width / 2)));
                        desk.y = Math.min(ROOM.height - box.height, Math.max(0, Math.round(cell.y - box.height / 2)));
                    }

                    const position = cell && deskFits(desk, desks) ? desk : findDeskPosition(desk, desks);

                    if (null === position) {
                        return;
                    }

                    desks.push({...desk, x: position.x, y: position.y});
                    this.roomEditor.selected = desk.id;
                },

                onDeskTypeDragStart(event, type) {
                    event.dataTransfer.effectAllowed = 'copy';
                    event.dataTransfer.setData('text/plain', type);
                    this.roomEditor.newType = type;
                },

                onRoomDrop(event) {
                    if (this.roomEditor.newType) {
                        this.addDesk(this.roomEditor.newType, this.roomCell(event));
                        this.roomEditor.newType = null;
                    }
                },

                // Move a desk with the pointer (mouse, pen or finger)
                onDeskPointerDown(event, desk) {
                    this.roomEditor.selected = desk.id;
                    this.roomEditor.move = {id: desk.id, start: this.roomCell(event), x: desk.x, y: desk.y};

                    event.currentTarget.setPointerCapture(event.pointerId);
                },

                onDeskPointerMove(event) {
                    const move = this.roomEditor.move;

                    if (!move) {
                        return;
                    }

                    const cell = this.roomCell(event);
                    const desk = this.roomEditor.desks.find((item) => item.id === move.id);
                    const target = {...desk, x: move.x + Math.round(cell.x - move.start.x), y: move.y + Math.round(cell.y - move.start.y)};

                    // The desk stops at the walls and at other desks
                    if (deskFits(target, this.roomEditor.desks)) {
                        desk.x = target.x;
                        desk.y = target.y;
                    }
                },

                onDeskPointerUp() {
                    this.roomEditor.move = null;
                },

                // Arrow keys move the selected desk, R turns it, Delete removes it
                onDeskKey(event, desk) {
                    const steps = {ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1]};

                    this.roomEditor.selected = desk.id;

                    if (steps[event.key]) {
                        const item = this.roomEditor.desks.find((other) => other.id === desk.id);
                        const target = {...item, x: item.x + steps[event.key][0], y: item.y + steps[event.key][1]};

                        if (deskFits(target, this.roomEditor.desks)) {
                            item.x = target.x;
                            item.y = target.y;
                        }
                    } else if ('r' === event.key || 'R' === event.key) {
                        this.rotateDesk();
                    } else if ('Delete' === event.key || 'Backspace' === event.key) {
                        this.removeDesk();
                    } else {
                        return;
                    }

                    event.preventDefault();
                },

                rotateDesk() {
                    const desk = this.roomEditor.desks.find((item) => item.id === this.roomEditor.selected);

                    if (!desk) {
                        return;
                    }

                    const target = {...desk, r: 90 === desk.r ? 0 : 90};
                    const box = deskBox(target);

                    // Keep the desk inside the room
                    target.x = Math.min(target.x, ROOM.width - box.width);
                    target.y = Math.min(target.y, ROOM.height - box.height);

                    if (deskFits(target, this.roomEditor.desks)) {
                        Object.assign(desk, target);
                    }
                },

                removeDesk() {
                    this.roomEditor.desks = this.roomEditor.desks.filter((item) => item.id !== this.roomEditor.selected);
                    this.roomEditor.selected = null;
                },

                showSeating() {
                    this.view = 'seating';
                    this.error = null;

                    // The classrooms and the students may have been changed in the settings
                    this.loadMasterData().then(() => {
                        // Keep a plan that is not saved yet
                        if (this.master && !this.seatTool.dirty) {
                            this.loadSeating();
                        }
                    });
                },

                /**
                 * Show the stored seating plan of the selected group
                 */
                loadSeating() {
                    const seating = this.seatGroup ? parseSeating(this.seatGroup.seating, this.seatLayout, this.seatStudents) : {seats: {}, blocked: {}, fixed: {}};

                    Object.assign(this.seatTool, {...seating, picked: null, dirty: false, saved: false});
                },

                changeSeating(changes) {
                    Object.assign(this.seatTool, {...changes, picked: null, dirty: true, saved: false});
                },

                /**
                 * Draw the seats. Students on fixed seats stay where they are.
                 */
                drawSeats() {
                    const tool = this.seatTool;
                    const kept = Object.fromEntries(Object.entries(tool.seats).filter(([key]) => tool.fixed[key]));

                    this.changeSeating({seats: drawSeating(this.seatLayout, tool.blocked, kept, this.seatStudents, tool.mix)});
                },

                clearSeats() {
                    this.changeSeating({seats: {}, fixed: {}});
                },

                // Fixed seats are kept when the plan is drawn
                toggleFixed(seat) {
                    const fixed = {...this.seatTool.fixed};

                    if (fixed[seat.key]) {
                        delete fixed[seat.key];
                    } else {
                        fixed[seat.key] = true;
                    }

                    this.changeSeating({fixed: fixed});
                },

                /**
                 * Put a student on a seat by hand: the seat is fixed then.
                 * Whoever sits there takes the seat the student comes from.
                 */
                moveStudent(studentId, key) {
                    const seats = {...this.seatTool.seats};
                    const fixed = {...this.seatTool.fixed, [key]: true};
                    const from = Object.keys(seats).find((seat) => seats[seat] === studentId);
                    const other = seats[key];

                    if (this.seatTool.blocked[key] || from === key) {
                        this.seatTool.picked = null;

                        return;
                    }

                    if (from) {
                        delete seats[from];
                        delete fixed[from];
                    }

                    if (undefined !== other && from) {
                        seats[from] = other;
                    }

                    seats[key] = studentId;

                    this.changeSeating({seats: seats, fixed: fixed});
                },

                unseatStudent(studentId) {
                    const seats = {...this.seatTool.seats};
                    const fixed = {...this.seatTool.fixed};
                    const key = Object.keys(seats).find((seat) => seats[seat] === studentId);

                    if (key) {
                        delete seats[key];
                        delete fixed[key];

                        this.changeSeating({seats: seats, fixed: fixed});
                    }
                },

                /**
                 * A click on a seat: move the picked student to it, else pick its student,
                 * else block or unblock the empty seat.
                 */
                onSeatClick(seat) {
                    const tool = this.seatTool;

                    if (null !== tool.picked) {
                        this.moveStudent(tool.picked, seat.key);
                    } else if (seat.student) {
                        tool.picked = seat.student.id;
                    } else {
                        const blocked = {...tool.blocked};

                        if (blocked[seat.key]) {
                            delete blocked[seat.key];
                        } else {
                            blocked[seat.key] = true;
                        }

                        this.changeSeating({blocked: blocked});
                    }
                },

                pickStudent(student) {
                    this.seatTool.picked = this.seatTool.picked === student.id ? null : student.id;
                },

                onStudentDragStart(event, student) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(student.id));
                    this.seatTool.dragged = student.id;
                },

                onSeatDrop(seat) {
                    if (this.seatTool.dragged) {
                        this.moveStudent(this.seatTool.dragged, seat.key);
                        this.seatTool.dragged = null;
                    }
                },

                onUnseatedDrop() {
                    if (this.seatTool.dragged) {
                        this.unseatStudent(this.seatTool.dragged);
                        this.seatTool.dragged = null;
                    }
                },

                deskClass(desk) {
                    return ['cb-desk--' + desk.type, {'cb-desk--selected': desk.id === this.roomEditor.selected}];
                },

                seatClass(seat) {
                    return {
                        'cb-seat--taken': null !== seat.student,
                        'cb-seat--blocked': seat.blocked,
                        'cb-seat--fixed': seat.fixed,
                        'cb-seat--picked': null !== seat.student && seat.student.id === this.seatTool.picked,
                    };
                },

                seatLabel(seat) {
                    if (seat.student) {
                        return seat.student.firstname + ' ' + seat.student.lastname;
                    }

                    return seat.blocked ? this.labels.seatingBlocked : this.labels.seatingFree;
                },

                /**
                 * Store the seating plan with the group
                 */
                async saveSeating() {
                    const group = this.seatGroup;
                    const seating = JSON.stringify({seats: this.seatTool.seats, blocked: Object.keys(this.seatTool.blocked), fixed: Object.keys(this.seatTool.fixed)});
                    const payload = {resource: 'group', id: group.id, data: {...group, seating: seating}};
                    const error = await this.run(() => this.request(this.config.urls.masterDataSave, payload));

                    if (null === error) {
                        await this.loadMasterData();

                        this.seatTool.dirty = false;
                        this.seatTool.saved = true;
                    }
                },

                /**
                 * Print the seating plan only, on a landscape page. The browser can save the print as a PDF file.
                 */
                printSeating() {
                    const root = document.documentElement;
                    const style = document.createElement('style');

                    // The page format cannot be set for a part of a style sheet, so it is added for this print only
                    style.textContent = '@page { size: A4 landscape; margin: 12mm; }';
                    document.head.appendChild(style);
                    root.classList.add('cb-print-seating');

                    window.addEventListener('afterprint', () => {
                        root.classList.remove('cb-print-seating');
                        style.remove();
                    }, {once: true});

                    window.print();
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
                        const data = await this.request(this.config.urls.week, {date: date});

                        this.timetable = data.timetable;
                        this.entries = data.entries;
                        this.birthdays = data.birthdays;
                        this.important = data.important;
                        this.openCount = data.openCount;
                        this.week = data.week;

                        // The displayed day has to be a day of the loaded week
                        if (!data.week.days.some((day) => day.date === this.day)) {
                            this.day = (data.week.days.find((day) => day.isToday) || data.week.days[0]).date;
                        }
                    });
                },

                loadMasterData() {
                    return this.run(async () => {
                        const master = await this.request(this.config.urls.masterDataList, {});

                        if (!this.resource) {
                            this.resource = Object.keys(master.schema)[0];
                        }

                        // Preselect the current school year
                        if (!master.data.schoolYear.some((year) => year.id === this.timetableYear)) {
                            const today = this.week ? this.week.today : '';
                            const current = master.data.schoolYear.find((year) => year.startDate <= today && today <= year.endDate);

                            this.timetableYear = (current || master.data.schoolYear[0] || {id: null}).id;
                        }

                        this.master = master;
                    });
                },

                selectResource(name) {
                    this.resource = name;
                    this.form = null;
                    this.roomEditor = null;
                    this.lessonForm = null;
                    this.deleteId = null;
                    this.error = null;

                    if ('security' === name) {
                        this.loadSecurity();
                    }
                },

                // ---------------------------------------------------------------
                // Security: two-factor authentication
                // ---------------------------------------------------------------

                /**
                 * Call the two-factor API and take over the status of the response
                 */
                twoFactor(url, data, changes) {
                    return this.run(async () => {
                        const response = await this.request(url, data);

                        Object.assign(this.security, {
                            status: {enabled: response.enabled, backupCodes: response.backupCodes},
                            invalid: false,
                            confirmDisable: false,
                            ...changes(response),
                        });
                    });
                },

                // Open the security settings from the user menu
                openSecurity() {
                    this.userMenu = false;
                    this.view = 'settings';
                    this.error = null;

                    this.loadMasterData().then(() => this.selectResource('security'));
                },

                loadSecurity() {
                    return this.twoFactor(this.config.urls.twoFactorStatus, {}, () => ({setup: null, code: '', codes: null}));
                },

                // Show the QR code for the authenticator app
                startTwoFactor() {
                    return this.twoFactor(this.config.urls.twoFactorSetup, {}, (response) => ({
                        setup: {secret: response.setup.secret, image: 'data:image/svg+xml;base64,' + response.setup.qrCode},
                        code: '',
                    }));
                },

                async enableTwoFactor() {
                    const error = await this.twoFactor(this.config.urls.twoFactorEnable, {code: this.security.code}, () => ({setup: null, code: ''}));

                    // A wrong code is shown at the field
                    if (error instanceof ApiError && 'validation' === error.code) {
                        this.error = null;
                        this.security.invalid = true;
                    }
                },

                disableTwoFactor() {
                    return this.twoFactor(this.config.urls.twoFactorDisable, {}, () => ({setup: null, codes: null}));
                },

                // The codes are shown once, the server only stores their hashes
                generateBackupCodes() {
                    return this.twoFactor(this.config.urls.twoFactorBackupCodes, {}, (response) => ({codes: response.codes}));
                },

                openForm(record) {
                    const data = {};

                    Object.keys(this.fields).forEach((name) => {
                        const field = this.fields[name];
                        const fallback = {color: field.options ? field.options[0] : '#2563eb', references: []}[field.type] || '';

                        // Copy arrays, the form must not change the record
                        data[name] = record ? (Array.isArray(record[name]) ? [...record[name]] : record[name]) : fallback;

                        // A colour from before the palette existed: preselect the closest one
                        if ('color' === field.type && field.options && !field.options.includes(data[name])) {
                            data[name] = /^#[0-9a-f]{6}$/i.test(data[name]) ? nearestColor(data[name], field.options) : field.options[0];
                        }
                    });

                    this.form = {id: record ? record.id : null, data: data, errors: {}};
                    this.roomEditor = 'classroom' === this.resource ? {desks: parseLayout(data.layout), selected: null, move: null, newType: null} : null;
                    this.deleteId = null;
                    this.error = null;
                },

                async saveRecord() {
                    if (this.roomEditor) {
                        this.form.data.layout = JSON.stringify({desks: this.roomEditor.desks});
                    }

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

                newEntry(date, periodId) {
                    this.showEntryForm(null, {
                        // The first type is the default: the lesson remark
                        type: this.entryTypes[0],
                        entryDate: date,
                        period: periodId || '',
                        entryTime: '',
                        content: '',
                        done: false,
                        important: false,
                        visibleFrom: '',
                        reminder: '',
                    });
                },

                openEntry(entry) {
                    const {id, ...data} = entry;

                    this.showEntryForm(id, {...data, period: data.period || ''});
                },

                showEntryForm(id, data) {
                    this.entryForm = {id: id, data: data, errors: {}, confirmDelete: false};
                    this.error = null;

                    this.$nextTick(() => {
                        if (this.$refs.entryContent) {
                            this.$refs.entryContent.focus();
                        }
                    });
                },

                closeEntry() {
                    this.entryForm = null;
                },

                /**
                 * Reload the week and, if it is shown, the list of the open entries
                 */
                async reloadEntries() {
                    await this.loadWeek('day' === this.view ? this.day : this.week.start);

                    if (null !== this.openEntries) {
                        await this.showOpenEntries();
                    }
                },

                async saveEntry() {
                    const form = this.entryForm;
                    const error = await this.run(() => this.request(this.config.urls.entrySave, {id: form.id, data: form.data}));

                    if (null === error) {
                        this.entryForm = null;
                        await this.reloadEntries();
                    } else if ('validation' === error.code) {
                        form.errors = error.data.errors;
                    }
                },

                async deleteEntry() {
                    const error = await this.run(() => this.request(this.config.urls.entryDelete, {id: this.entryForm.id}));

                    this.entryForm = null;

                    if (null === error) {
                        await this.reloadEntries();
                    }
                },

                async copyEntry(entry) {
                    await this.run(() => this.request(this.config.urls.entryCopy, {id: entry.id}));
                    await this.reloadEntries();
                },

                async toggleDone(entry) {
                    await this.run(() => this.request(this.config.urls.entryDone, {id: entry.id, done: !entry.done}));
                    await this.reloadEntries();
                },

                showOpenEntries() {
                    return this.run(async () => {
                        this.openEntries = (await this.request(this.config.urls.entryOpen, {})).entries;
                    });
                },

                // E.g. "Geburtstag: Zoe Muster, 2a (13)"
                birthdayText(birthday) {
                    const homeClass = birthday.homeClass ? ', ' + birthday.homeClass : '';

                    return this.labels.birthday + ': ' + birthday.firstname + ' ' + birthday.lastname + homeClass + ' (' + birthday.age + ')';
                },

                // E.g. "Heute", "Morgen" or "Fr., 27.11. (in 7 Tagen)"
                formatRelativeDate(date) {
                    const days = daysBetween(this.week.today, date);

                    if (0 === days) {
                        return this.labels.today;
                    }

                    if (1 === days) {
                        return this.labels.tomorrow;
                    }

                    const short = new Intl.DateTimeFormat(locale, {weekday: 'short', day: 'numeric', month: 'numeric'}).format(parseDate(date));

                    return short + ' (' + this.labels.inDays.replace('%d', days) + ')';
                },

                formatLongDate(date) {
                    return new Intl.DateTimeFormat(locale, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}).format(parseDate(date));
                },

                entryError(name) {
                    return this.labels.errors[this.entryForm.errors[name]];
                },

                entryClass(entry) {
                    const target = this.drag ? this.drag.target : null;
                    const isTarget = null !== target && target.entryId === entry.id && this.drag.entry.id !== entry.id;

                    return ['cb-entry--' + entry.type, {
                        'cb-entry--done': 'task' === entry.type && entry.done,
                        'cb-entry--important': entry.important,
                        'cb-entry--dragging': null !== this.drag && this.drag.entry.id === entry.id,
                        'cb-entry--drop-before': isTarget && !target.after,
                        'cb-entry--drop-after': isTarget && target.after,
                    }];
                },

                onDragStart(entry, event) {
                    this.drag = {entry: entry, target: null};

                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', entry.content);
                },

                onDragEnd() {
                    this.drag = null;
                },

                /**
                 * The pointer is over a slot but not over an entry: drop at the end of the slot
                 */
                onDragOverSlot(date, periodId, event) {
                    if (null === this.drag) {
                        return;
                    }

                    // Allow the drop
                    event.preventDefault();

                    this.drag.target = {date: date, period: periodId, entryId: null, after: true};
                },

                /**
                 * The pointer is over an entry: drop before or after it
                 */
                onDragOverEntry(entry, event) {
                    if (null === this.drag) {
                        return;
                    }

                    const box = event.currentTarget.getBoundingClientRect();

                    this.drag.target = {
                        date: entry.entryDate,
                        period: entry.period,
                        entryId: entry.id,
                        after: event.clientY > box.top + box.height / 2,
                    };
                },

                isDropSlot(date, periodId) {
                    const target = this.drag ? this.drag.target : null;

                    return null !== target && target.date === date && target.period === periodId;
                },

                async onDrop() {
                    const drag = this.drag;

                    this.drag = null;

                    if (null === drag || null === drag.target) {
                        return;
                    }

                    // The position among the other entries of the target slot
                    const target = drag.target;
                    const slot = this.entries.filter((entry) => entry.entryDate === target.date && entry.period === target.period);
                    const others = slot.filter((entry) => entry.id !== drag.entry.id);
                    let position = others.length;

                    if (target.entryId === drag.entry.id) {
                        // Dropped onto itself
                        position = slot.findIndex((entry) => entry.id === drag.entry.id);
                    } else if (null !== target.entryId) {
                        position = others.findIndex((entry) => entry.id === target.entryId) + (target.after ? 1 : 0);
                    }

                    const unchanged = drag.entry.entryDate === target.date
                        && drag.entry.period === target.period
                        && position === slot.findIndex((entry) => entry.id === drag.entry.id)
                    ;

                    if (unchanged) {
                        return;
                    }

                    const payload = {id: drag.entry.id, entryDate: target.date, period: target.period, position: position};

                    await this.run(() => this.request(this.config.urls.entryMove, payload));
                    await this.reloadEntries();
                },

                // E.g. "1 (07:30–08:15)"
                periodLabel(period) {
                    return period.number + ' (' + period.startTime + '\u2013' + period.endTime + ')';
                },

                openLesson(weekday, period, lesson) {
                    this.lessonForm = {
                        id: lesson ? lesson.id : null,
                        weekday: weekday,
                        period: period,
                        data: {
                            subject: lesson ? lesson.subject : '',
                            learningGroup: lesson ? lesson.learningGroup : '',
                            room: lesson ? lesson.room : '',
                        },
                        errors: {},
                        confirmDelete: false,
                    };

                    this.error = null;
                },

                async saveLesson() {
                    const form = this.lessonForm;

                    const payload = {
                        resource: 'lesson',
                        id: form.id,
                        data: {...form.data, schoolYear: this.timetableYear, weekday: form.weekday, period: form.period.id},
                    };

                    const error = await this.run(() => this.request(this.config.urls.masterDataSave, payload));

                    if (null === error) {
                        this.lessonForm = null;
                        await this.loadMasterData();
                    } else if ('validation' === error.code) {
                        form.errors = error.data.errors;
                    }
                },

                async deleteLesson() {
                    const payload = {resource: 'lesson', id: this.lessonForm.id};
                    const error = await this.run(() => this.request(this.config.urls.masterDataDelete, payload));

                    this.lessonForm = null;

                    if (null === error) {
                        await this.loadMasterData();
                    }
                },

                lessonError(name) {
                    return this.labels.errors[this.lessonForm.errors[name]];
                },

                weekdayName(weekday) {
                    return new Intl.DateTimeFormat(locale, {weekday: 'long'}).format(weekdayDate(weekday));
                },

                // E.g. "Montag, 1 (07:30–08:15)"
                slotLabel(weekday, period) {
                    return this.weekdayName(weekday) + ', ' + this.labels.fields.period + ' ' + this.periodLabel(period);
                },

                inputType(field) {
                    return {integer: 'number', date: 'date', time: 'time', color: 'color'}[field.type] || 'text';
                },

                /**
                 * The records a reference field can point to
                 */
                referenceOptions(field) {
                    const where = field.where || {};

                    return this.master.data[field.resource].filter((item) => Object.keys(where).every((key) => item[key] === where[key]));
                },

                optionLabel(name, option) {
                    return this.labels.options[name][option];
                },

                // The name of a palette colour, else the value itself
                colorName(value, field, name) {
                    return field.options && field.options.includes(value) ? this.optionLabel(name, value) : value;
                },

                formatValue(value, field, name) {
                    const titles = (ids) => this.master.data[field.resource].filter((item) => ids.includes(item.id)).map((item) => item.title).join(', ');

                    switch (field.type) {
                        case 'date':
                            return value ? this.formatDate(value) : '';
                        case 'select':
                            return '' === value ? '' : this.optionLabel(name, value);
                        case 'reference':
                            return titles([value]);
                        case 'references':
                            return titles(value);
                        default:
                            return value;
                    }
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
        // An icon of the icon set (see class-book-icons.js), in the colour of the text
        app.component('cb-icon', {
            props: {name: {type: String, required: true}},
            computed: {
                icon() {
                    return (window.ClassBookIcons || {})[this.name] || {viewBox: '0 0 1 1', path: ''};
                },
            },
            template: '<svg class="cb-icon" :viewBox="icon.viewBox" aria-hidden="true" focusable="false" fill="currentColor"><path :d="icon.path"></path></svg>',
        });

        app.mount(element);
    };

    const init = () => {
        document.querySelectorAll('[data-class-book]').forEach(createClassBook);

        // The login form has no Vue app, it only gets the theme
        document.querySelectorAll('.cb-app[data-cb-static]').forEach((element) => {
            element.dataset.theme = getInitialTheme();
        });
    };

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
