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
    const SORT_KEY = 'cb-sort';
    const STATE_KEY = 'cb-state';

    // Form of a learning group: how many students the search lists at most, the columns
    // of the list of its students and the name its order is stored under
    const MEMBER_MATCHES = 8;
    const MEMBER_COLUMNS = ['lastname', 'firstname', 'gender', 'homeClass'];
    const MEMBER_LIST = 'groupMembers';
    const VIEWS = ['week', 'day', 'groups', 'seating', 'settings'];

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

    /**
     * Set the theme of the app. In standalone mode the page has the background
     * of the app, so the document gets the theme as well (html.cb-standalone).
     */
    const applyTheme = (element, theme) => {
        element.dataset.theme = theme;

        if (document.documentElement.classList.contains('cb-standalone')) {
            document.documentElement.dataset.cbTheme = theme;
        }
    };

    // Whether the week view shows Saturday and Sunday (the choice of the user, off by default)
    const getInitialWeekend = () => {
        try {
            return '1' === window.localStorage.getItem(WEEKEND_KEY);
        } catch (error) {
            return false;
        }
    };

    // Where the user was (view, tab of the settings, date), kept for this browser tab only,
    // so that reloading the page leads back there. The login page forgets it: after a
    // login the app starts with the week.
    const readState = () => {
        try {
            const state = JSON.parse(window.sessionStorage.getItem(STATE_KEY) || 'null');

            if (!state || !VIEWS.includes(state.view)) {
                return null;
            }

            return {
                view: state.view,
                resource: 'string' === typeof state.resource ? state.resource : null,
                date: /^\d{4}-\d{2}-\d{2}$/.test(state.date || '') ? state.date : null,
            };
        } catch (error) {
            return null;
        }
    };

    const clearState = () => {
        try {
            window.sessionStorage.removeItem(STATE_KEY);
        } catch (error) {
            // No storage, nothing to forget
        }
    };

    // The order of the lists in the settings (the choice of the user)
    const readSort = () => {
        try {
            const sort = JSON.parse(window.localStorage.getItem(SORT_KEY) || '{}');
            const valid = (levels) => Array.isArray(levels) && levels.every((level) => level && 'string' === typeof level.field);

            return Object.fromEntries(Object.entries(sort && 'object' === typeof sort ? sort : {}).filter(([, levels]) => valid(levels)));
        } catch (error) {
            return {};
        }
    };

    // Compares two values of a column. Empty values come last in both directions,
    // texts are compared like a person would ("9.2" before "10.1", "a" next to "A").
    const sortCollator = new Intl.Collator(document.documentElement.lang || 'de', {numeric: true, sensitivity: 'base'});

    const compareSortKeys = (a, b, desc) => {
        const emptyA = '' === a || null === a || undefined === a;
        const emptyB = '' === b || null === b || undefined === b;

        if (emptyA || emptyB) {
            return emptyA === emptyB ? 0 : (emptyA ? 1 : -1);
        }

        const result = 'number' === typeof a && 'number' === typeof b ? a - b : sortCollator.compare(String(a), String(b));

        return desc ? -result : result;
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
    const ROOM = {width: 40, height: 40};
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
     * The seating plans of a group, one per classroom: {classroom id: plan}.
     *
     * Stored as {rooms: {classroom id: plan}}. Until a group could have lessons in several
     * rooms there was one plan only, stored without "rooms": it belongs to the main room.
     */
    const readSeatingRooms = (json, mainRoom) => {
        let stored = null;

        try {
            stored = JSON.parse(json || '{}');
        } catch (error) {
            // No seating plan
        }

        if (!stored || 'object' !== typeof stored) {
            return {};
        }

        if (stored.rooms && 'object' === typeof stored.rooms) {
            return {...stored.rooms};
        }

        return mainRoom && (stored.seats || stored.blocked) ? {[mainRoom]: stored} : {};
    };

    /**
     * A stored seating plan ({seats: {seat: student}, blocked: [seat], fixed: [seat]}),
     * reduced to the seats of the room and the students of the group.
     */
    const parseSeating = (seating, desks, students) => {
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

    // Moving an entry with a finger: how long the finger has to rest on the entry (ms), how far
    // it may move meanwhile (px), and the edge of the window that scrolls (px, px per frame)
    const TOUCH_HOLD = 350;
    const TOUCH_TOLERANCE = 10;
    const TOUCH_EDGE = 56;
    const TOUCH_SPEED = 10;

    // A horizontal swipe of at least 5 cm changes the week or the day. A CSS inch has 96 px
    // and 2.54 cm; on most devices that is close to the real length
    const SWIPE_DISTANCE = Math.round(5 * 96 / 2.54);

    // Holding an arrow of the navigation runs through the weeks or days: it starts after
    // HOLD_DELAY, takes a step every HOLD_INTERVAL and gets faster after HOLD_FAST_AFTER steps
    const HOLD_DELAY = 450;
    const HOLD_INTERVAL = 220;
    const HOLD_FAST_INTERVAL = 90;
    const HOLD_FAST_AFTER = 8;

    // Not reactive: the timer of a held arrow and whether the click that follows is ignored
    let holdTimer = null;
    let holdDone = false;

    /**
     * The ISO 8601 week number of a date
     */
    function isoWeek(date) {
        const day = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));

        // The week belongs to the year of its Thursday
        day.setUTCDate(day.getUTCDate() + 4 - (day.getUTCDay() || 7));

        return Math.ceil(((day - Date.UTC(day.getUTCFullYear(), 0, 1)) / 86400000 + 1) / 7);
    }

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

                    // Small screens: the main navigation is an off-canvas panel
                    navOpen: false,

                    // An arrow of the navigation is held: {step, count}
                    hold: null,

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

                    // List of the students: the home class shown (0 = all), the ticked students
                    // and the learning group they are put into or taken out of
                    studentFilter: 0,
                    selected: [],
                    bulkGroup: 0,

                    // Form of a learning group: its students, saved with the group
                    // {ids: the students, query: what is typed in the search, active: marked match} or null
                    members: null,

                    // The dialog that asks before something is deleted: {name, run} or null
                    confirm: null,

                    // Random groups: the class or learning group, the mode ("count" or "size")
                    // with its value, the deselected students by id and the groups
                    groupTool: {groupId: '', mode: 'count', value: 4, absent: {}, result: null},

                    // Security (settings): the state of the two-factor authentication, the running
                    // setup ({secret, image}), the entered code and the new backup codes
                    security: {status: null, setup: null, code: '', invalid: false, codes: null, confirmDisable: false},

                    // Delete the account (settings, security): the password and the error at the field
                    account: {confirm: false, password: '', error: null},

                    // The user name (settings, security): the value of the field, its error and whether it was saved
                    username: {value: config.member.username, error: null, saved: false},

                    // The order of the lists in the settings: {resource: [{field, desc}]}
                    sort: readSort(),

                    // The e-mail address (settings, security): the form, its errors per field and
                    // the address a link was sent to
                    email: {open: false, value: '', password: '', errors: {}, sent: null},

                    // Classroom editor (settings): the desks of the open classroom, the selected
                    // desk and the desk that is being moved with the pointer
                    roomEditor: null,

                    // Seating plan: the class or learning group, the seats ({seat: student id}),
                    // the blocked seats, the fixed seats (set by hand, kept when the plan is drawn),
                    // the picked student (to move him or her with two clicks) and whether the plan
                    // differs from the stored one
                    seatTool: {groupId: '', roomId: '', mix: true, seats: {}, blocked: {}, fixed: {}, picked: null, dragged: null, dirty: false, saved: false},

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

                // The rooms a lesson can take place in: the classrooms, and the room of the lesson
                // if it is none of them (entered as text before the classrooms were offered)
                lessonRooms() {
                    const rooms = this.master.data.classroom.map((classroom) => classroom.title);
                    const current = this.lessonForm ? this.lessonForm.data.room : '';

                    if ('' !== current && !rooms.includes(current)) {
                        rooms.push(current);
                    }

                    return rooms;
                },

                usernameError() {
                    return this.labels.usernameErrors[this.username.error] || this.labels.usernameErrors.invalid;
                },

                // The message after the link was sent, with the address it was sent to
                emailSent() {
                    return this.labels.emailSent.replace('%s', this.email.sent || '');
                },

                // The students are added and edited in a dialog, the other lists have their form below
                formModal() {
                    return 'student' === this.resource;
                },

                // The records of the open list as shown: sorted, the students of one home class only
                listRecords() {
                    if ('student' !== this.resource || !this.studentFilter) {
                        return this.sortedRecords;
                    }

                    return this.sortedRecords.filter((record) => record.homeClass === this.studentFilter);
                },

                homeClasses() {
                    return this.referenceOptions(this.master.schema.student.homeClass);
                },

                learningGroupOptions() {
                    return this.referenceOptions(this.master.schema.student.learningGroups);
                },

                memberColumns() {
                    return MEMBER_COLUMNS;
                },

                memberList() {
                    return MEMBER_LIST;
                },

                memberSortLevels() {
                    return (this.sort[MEMBER_LIST] || []).filter((level) => MEMBER_COLUMNS.includes(level.field));
                },

                // Form of a learning group: its students, sorted by the columns the user has
                // chosen (several levels like in the lists), else by name
                memberRows() {
                    const levels = this.memberSortLevels;
                    const byName = (a, b) => sortCollator.compare(a.lastname + ' ' + a.firstname, b.lastname + ' ' + b.firstname);

                    return this.master.data.student
                        .filter((student) => this.members.ids.includes(student.id))
                        .sort((a, b) => {
                            for (const level of levels) {
                                const result = compareSortKeys(this.memberValue(a, level.field), this.memberValue(b, level.field), level.desc);

                                if (0 !== result) {
                                    return result;
                                }
                            }

                            return byName(a, b);
                        });
                },

                // The students the search finds: every word typed has to be part of the name
                // or of the home class ("9.2" finds the whole class). Members are left out.
                allMemberMatches() {
                    const words = this.members.query.toLowerCase().split(/\s+/).filter(Boolean);

                    if (!words.length) {
                        return [];
                    }

                    return this.master.data.student
                        .filter((student) => {
                            const text = [student.lastname, student.firstname, this.homeClassTitle(student)].join(' ').toLowerCase();

                            return !this.members.ids.includes(student.id) && words.every((word) => text.includes(word));
                        })
                        .sort((a, b) => sortCollator.compare(a.lastname + ' ' + a.firstname, b.lastname + ' ' + b.firstname));
                },

                memberMatchCount() {
                    return this.allMemberMatches.length;
                },

                // Only the first ones are listed, "add all" takes all of them
                memberMatches() {
                    return this.allMemberMatches.slice(0, MEMBER_MATCHES);
                },

                addAllLabel() {
                    return this.labels.membersAddAll.replace('%s', this.memberMatchCount);
                },

                membersTitle() {
                    return this.labels.membersTitle.replace('%s', this.members.ids.length);
                },

                // The ticked students that are shown: a filter set later hides ticked ones
                selectedVisible() {
                    return this.listRecords.filter((record) => this.selected.includes(record.id));
                },

                allSelected() {
                    return this.listRecords.length > 0 && this.selectedVisible.length === this.listRecords.length;
                },

                selectedCount() {
                    return this.labels.studentSelected.replace('%s', this.selectedVisible.length);
                },

                // The question of the dialog that confirms a deletion
                confirmText() {
                    return this.confirm && this.confirm.name
                        ? this.labels.confirmDeleteNamed.replace('%s', this.confirm.name)
                        : this.labels.confirmDelete;
                },

                accountError() {
                    return 'tooMany' === this.account.error ? this.labels.accountDeleteTooMany : this.labels.accountDeleteInvalid;
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
                    return this.timetable.periods.map((period) => {
                        const cells = this.weekDays.map((day) => {
                            const lesson = this.timetable.days[day.date].lessons.find((item) => item.period === period.id);

                            return {
                                day: day,
                                lesson: lesson ? presentLesson(lesson, this.timetable.subjects, this.timetable.groups, this.labels) : null,
                                entries: this.entries.filter((entry) => entry.entryDate === day.date && entry.period === period.id),
                            };
                        });

                        // A row without a lesson and without an entry is shown very low
                        return {period: period, cells: cells, empty: cells.every((cell) => null === cell.lesson && 0 === cell.entries.length)};
                    });
                },

                // The row of the whole day without an entry and without a birthday is shown very low
                dayRowEmpty() {
                    return this.dayRow.every((cell) => 0 === cell.entries.length && 0 === cell.birthdays.length);
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
                        text: (entry.entryTime ? entry.entryTime + ' ' : '') + entry.content + (entry.location ? ' (' + entry.location + ')' : ''),
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

                // The date the navigation shows: the first day of the week or the day. While an
                // arrow is held, it runs ahead of the loaded data.
                navDate() {
                    const dayView = 'day' === this.view;
                    const date = parseDate(dayView ? this.day : this.week.start);

                    if (this.hold) {
                        date.setDate(date.getDate() + this.hold.count * (dayView ? 1 : 7));
                    }

                    return formatIsoDate(date);
                },

                navWeek() {
                    return this.hold ? isoWeek(parseDate(this.navDate)) : this.week.week;
                },

                weekRange() {
                    const format = new Intl.DateTimeFormat(locale, {day: 'numeric', month: 'long', year: 'numeric'});
                    const start = parseDate(this.navDate);
                    const end = parseDate(this.navDate);

                    end.setDate(end.getDate() + 6);

                    return format.formatRange(start, end);
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

                // The classroom the seating plan is for: a group can have lessons in several rooms
                seatRoom() {
                    return this.seatGroup ? this.master.data.classroom.find((item) => item.id === this.seatTool.roomId) || null : null;
                },

                // The classrooms to choose from: first the rooms of the group (its main room,
                // the rooms of its lessons in the timetable, rooms with a stored plan), then the others
                seatRoomOptions() {
                    const group = this.seatGroup;

                    if (!group) {
                        return {own: [], others: []};
                    }

                    const lessonRooms = this.master.data.lesson.filter((lesson) => lesson.learningGroup === group.id).map((lesson) => lesson.room);
                    const stored = Object.keys(readSeatingRooms(group.seating, group.classroom)).map(Number);
                    const isOwn = (room) => room.id === group.classroom || lessonRooms.includes(room.title) || stored.includes(room.id);
                    const option = (room) => ({id: room.id, title: room.id === group.classroom ? this.labels.seatingMainRoom.replace('%s', room.title) : room.title});
                    const rooms = [...this.master.data.classroom].sort((a, b) => (b.id === group.classroom) - (a.id === group.classroom));

                    return {own: rooms.filter(isOwn).map(option), others: rooms.filter((room) => !isOwn(room)).map(option)};
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

                // The sort levels of the open list: [{field, desc}], the first one counts most
                sortLevels() {
                    return (this.sort[this.resource] || []).filter((level) => this.visibleFields[level.field]);
                },

                // The records of the open list, sorted by the levels the user has chosen.
                // Without a level they stay in the order of the server.
                sortedRecords() {
                    const levels = this.sortLevels;

                    if (!levels.length) {
                        return this.records;
                    }

                    const keys = new Map(this.records.map((record) => [record.id, levels.map((level) => this.sortKey(record, level.field))]));

                    return [...this.records].sort((a, b) => {
                        for (let index = 0; index < levels.length; index++) {
                            const result = compareSortKeys(keys.get(a.id)[index], keys.get(b.id)[index], levels[index].desc);

                            if (0 !== result) {
                                return result;
                            }
                        }

                        return 0;
                    });
                },

                // E.g. "Noch 8 Backup-Codes"
                backupCodesCount() {
                    return this.labels.backupCodesCount.replace('%d', this.security.status.backupCodes);
                },
            },

            created() {
                // Vue does not manage the attributes of the root element
                applyTheme(element, this.theme);
            },

            mounted() {
                // The entry that is touched or moved with a finger, see onTouchStart(). Not reactive
                this.touch = null;

                // Swipe sideways in the week or day view, see onSwipeEnd()
                this.swipe = null;

                element.addEventListener('touchstart', (event) => this.onSwipeStart(event), {passive: true});
                element.addEventListener('touchend', (event) => this.onSwipeEnd(event), {passive: true});
                element.addEventListener('touchcancel', () => { this.swipe = null; }, {passive: true});

                // The day view is the better start on a phone
                if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) {
                    this.view = 'day';
                }

                // After a reload of the page: back to where the user was
                const state = readState();

                if (state) {
                    this.view = state.view;
                    this.day = 'day' === state.view ? state.date : null;
                }

                // A click outside or the escape key closes the user menu
                document.addEventListener('click', (event) => {
                    if (!event.target.closest('.cb-user')) {
                        this.userMenu = false;
                    }
                });

                // The escape key also closes the open dialog: the entry first, then the list behind it.
                // A dialog is closed with this key or with its close button only, not by a click beside it
                document.addEventListener('keydown', (event) => {
                    if ('Escape' !== event.key) {
                        return;
                    }

                    this.userMenu = false;
                    this.navOpen = false;

                    if (this.confirm) {
                        this.confirm = null;
                    } else if (this.entryForm) {
                        this.closeEntry();
                    } else if (this.lessonForm) {
                        this.lessonForm = null;
                    } else if (this.form && this.formModal) {
                        this.form = null;
                    } else if (this.openEntries) {
                        this.openEntries = null;
                    }
                });

                this.loadWeek(state ? state.date : null).then(() => {
                    if (state && 'groups' === state.view) {
                        this.showGroups();
                    } else if (state && 'seating' === state.view) {
                        this.showSeating();
                    } else if (state && 'settings' === state.view) {
                        this.loadMasterData().then(() => {
                            if (state.resource && ('security' === state.resource || this.master.schema[state.resource])) {
                                this.selectResource(state.resource);
                            }
                        });
                    }

                    // From now on remember where the user is
                    this.$watch(
                        () => JSON.stringify({
                            view: this.view,
                            resource: 'settings' === this.view ? this.resource : null,
                            date: 'day' === this.view ? this.day : (this.week ? this.week.start : null),
                        }),
                        (value) => {
                            try {
                                window.sessionStorage.setItem(STATE_KEY, value);
                            } catch (error) {
                                // No storage: a reload starts with the week
                            }
                        },
                        {immediate: true},
                    );
                });
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
                    applyTheme(element, this.theme);

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
                 * A click on an arrow of the navigation: one week or one day
                 */
                onStepClick(step) {
                    // The click that ends a hold has already been handled
                    if (holdDone) {
                        holdDone = false;

                        return Promise.resolve();
                    }

                    return 'day' === this.view ? this.shiftDay(step) : this.shiftWeeks(step);
                },

                /**
                 * An arrow of the navigation is pressed: if it is held, the weeks or days run
                 * through. Only the label and the date field change, the data is loaded on release.
                 */
                onHoldStart(step, event) {
                    if (event.button > 0 || this.loading) {
                        return;
                    }

                    window.clearTimeout(holdTimer);
                    holdDone = false;

                    const run = () => {
                        if (!this.hold) {
                            this.hold = {step: step, count: 0};
                        }

                        this.hold.count += step;

                        const interval = Math.abs(this.hold.count) >= HOLD_FAST_AFTER ? HOLD_FAST_INTERVAL : HOLD_INTERVAL;

                        holdTimer = window.setTimeout(run, interval);
                    };

                    holdTimer = window.setTimeout(run, HOLD_DELAY);
                },

                /**
                 * The arrow is released or left: show the week or the day that was reached
                 */
                onHoldEnd() {
                    window.clearTimeout(holdTimer);
                    holdTimer = null;

                    if (!this.hold) {
                        return Promise.resolve();
                    }

                    const date = this.navDate;

                    this.hold = null;
                    holdDone = true;

                    // Only the click of this release is ignored
                    window.setTimeout(() => {
                        holdDone = false;
                    }, 400);

                    return 'day' === this.view ? this.loadDay(date) : this.loadWeek(date);
                },

                /**
                 * No context menu from a long press on an arrow (touch)
                 */
                onHoldMenu(event) {
                    if (holdTimer || this.hold) {
                        event.preventDefault();
                    }
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

                // Another group: start with its main room, else with the first room it uses
                selectSeatGroup() {
                    const group = this.seatGroup;
                    const options = this.seatRoomOptions;

                    this.seatTool.roomId = group ? (group.classroom || (options.own[0] || options.others[0] || {id: ''}).id) : '';
                    this.loadSeating();
                },

                /**
                 * Show the stored seating plan of the selected group in the selected classroom
                 */
                loadSeating() {
                    const group = this.seatGroup;

                    // The room may have been deleted, or the group was chosen before rooms were
                    if (group && !this.seatRoom) {
                        this.seatTool.roomId = group.classroom || '';
                    }

                    const stored = group ? readSeatingRooms(group.seating, group.classroom)[this.seatTool.roomId] : null;
                    const seating = group ? parseSeating(stored, this.seatLayout, this.seatStudents) : {seats: {}, blocked: {}, fixed: {}};

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
                 * Store the seating plan with the group, for the selected classroom.
                 * The plans of the other rooms stay as they are.
                 */
                async saveSeating() {
                    const group = this.seatGroup;
                    const rooms = readSeatingRooms(group.seating, group.classroom);

                    rooms[this.seatTool.roomId] = {seats: this.seatTool.seats, blocked: Object.keys(this.seatTool.blocked), fixed: Object.keys(this.seatTool.fixed)};

                    const seating = JSON.stringify({rooms: rooms});
                    const payload = {resource: 'group', id: group.id, data: {...group, seating: seating}};
                    const error = await this.run(() => this.request(this.config.urls.masterDataSave, payload));

                    if (null === error) {
                        await this.loadMasterData();

                        this.seatTool.dirty = false;
                        this.seatTool.saved = true;
                    }
                },

                /**
                 * Print the seating plan only, on a portrait page (the room is square). The browser can save the print as a PDF file.
                 */
                printSeating() {
                    const root = document.documentElement;
                    const style = document.createElement('style');

                    // The page format cannot be set for a part of a style sheet, so it is added for this print only
                    style.textContent = '@page { size: A4 portrait; margin: 12mm; }';
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
                    this.members = null;
                    this.selected = [];
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

                async saveUsername() {
                    const error = await this.run(() => this.request(this.config.urls.accountUsername, {username: this.username.value}).then((response) => {
                        this.config.member.username = response.username;
                        this.username = {value: response.username, error: null, saved: true};
                    }));

                    // A name that is not allowed is shown at the field
                    if (error instanceof ApiError && 'validation' === error.code) {
                        this.error = null;
                        this.username.error = (error.data && error.data.errors && error.data.errors.username) || 'invalid';
                    }
                },

                openEmail() {
                    this.email = {open: true, value: '', password: '', errors: {}, sent: null};
                },

                resetEmail() {
                    this.email = {open: false, value: '', password: '', errors: {}, sent: null};
                },

                emailError(field) {
                    const errors = 'password' === field ? this.labels.emailPasswordErrors : this.labels.emailErrors;

                    return errors[this.email.errors[field]] || errors.invalid;
                },

                /**
                 * Ask for a new e-mail address. The server checks the password and sends a link
                 * to the new address; the address is only changed when the link is opened.
                 */
                async saveEmail() {
                    const address = this.email.value.trim().toLowerCase();
                    const error = await this.run(() => this.request(this.config.urls.accountEmail, {
                        email: address,
                        password: this.email.password,
                        path: window.location.pathname,
                    }));

                    if (error instanceof ApiError && 'validation' === error.code) {
                        this.error = null;
                        this.email.errors = (error.data && error.data.errors) || {email: 'invalid'};
                        this.email.password = '';

                        return;
                    }

                    if (null === error) {
                        this.email = {open: false, value: '', password: '', errors: {}, sent: address};
                    }
                },

                resetAccount() {
                    this.account = {confirm: false, password: '', error: null};
                },

                /**
                 * Delete the account with all data. The server checks the password,
                 * afterwards the member is logged out and the page shows the login form.
                 */
                async deleteAccount() {
                    const error = await this.run(() => this.request(this.config.urls.accountDelete, {password: this.account.password}));

                    if (error instanceof ApiError && 'validation' === error.code) {
                        this.error = null;
                        this.account.error = (error.data && error.data.errors && error.data.errors.password) || 'invalid';
                        this.account.password = '';

                        return;
                    }

                    if (null === error) {
                        window.location.href = window.location.pathname;
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

                    // A learning group shows its students, they are saved with the group
                    this.members = 'group' === this.resource
                        ? {query: '', active: 0, ids: record ? this.master.data.student.filter((student) => (student.learningGroups || []).includes(record.id)).map((student) => student.id) : []}
                        : null;
                    this.roomEditor = 'classroom' === this.resource ? {desks: parseLayout(data.layout), selected: null, move: null, newType: null} : null;
                    this.deleteId = null;
                    this.error = null;

                    if (this.formModal) {
                        this.focusFirstField('.cb-dialog--form');
                    }
                },

                // A dialog that has just been opened: the cursor is in its first field (wish of Marko)
                focusFirstField(selector) {
                    this.$nextTick(() => {
                        const field = element.querySelector(selector + ' input:not([type="hidden"]):not([disabled]), ' + selector + ' select:not([disabled]), ' + selector + ' textarea:not([disabled])');

                        if (field) {
                            field.focus();
                        }
                    });
                },

                async saveRecord() {
                    if (this.roomEditor) {
                        this.form.data.layout = JSON.stringify({desks: this.roomEditor.desks});
                    }

                    const payload = {resource: this.resource, id: this.form.id, data: this.form.data};
                    const members = this.members && 'group' === this.form.data.kind ? [...this.members.ids] : null;

                    const error = await this.run(async () => {
                        const response = await this.request(this.config.urls.masterDataSave, payload);

                        // The group exists now: put the ticked students in, take the others out
                        if (members) {
                            this.form.id = response.record.id;

                            for (const student of this.master.data.student) {
                                const isMember = (student.learningGroups || []).includes(response.record.id);

                                if (isMember !== members.includes(student.id)) {
                                    await this.saveStudentGroup(student, response.record.id, !isMember);
                                }
                            }
                        }
                    });

                    if (null === error) {
                        this.form = null;
                        this.members = null;
                        await this.loadMasterData();
                    } else if ('validation' === error.code && error.data.errors) {
                        this.form.errors = error.data.errors;
                    }
                },

                // Put one student into a learning group or take it out, all other fields stay
                saveStudentGroup(student, group, add) {
                    const data = {};

                    Object.keys(this.master.schema.student).forEach((name) => {
                        data[name] = Array.isArray(student[name]) ? [...student[name]] : student[name];
                    });

                    data.learningGroups = add ? [...data.learningGroups, group] : data.learningGroups.filter((id) => id !== group);

                    return this.request(this.config.urls.masterDataSave, {resource: 'student', id: student.id, data: data});
                },

                // Form of a learning group: add a student the search has found
                addMember(student) {
                    this.members.ids = [...this.members.ids, student.id];
                    this.members.query = '';
                    this.members.active = 0;

                    // Ready for the next name
                    this.$nextTick(() => {
                        const input = document.getElementById('cb-members-search');

                        if (input) {
                            input.focus();
                        }
                    });
                },

                // The enter key adds the marked match
                addMatch() {
                    const student = this.memberMatches[this.members.active];

                    if (student) {
                        this.addMember(student);
                    }
                },

                addAllMatches() {
                    this.members.ids = [...this.members.ids, ...this.allMemberMatches.map((student) => student.id)];
                    this.members.query = '';
                    this.members.active = 0;
                },

                moveMatch(step) {
                    const count = this.memberMatches.length;

                    if (count) {
                        this.members.active = (this.members.active + step + count) % count;
                    }
                },

                removeMember(student) {
                    this.members.ids = this.members.ids.filter((id) => id !== student.id);
                },

                // What a column of the list of members shows, also what it is sorted by
                memberValue(student, name) {
                    if ('homeClass' === name) {
                        return this.homeClassTitle(student);
                    }

                    if ('gender' === name) {
                        return student.gender ? this.labels.options.gender[student.gender] : '';
                    }

                    return student[name];
                },

                homeClassTitle(student) {
                    const item = this.master.data.group.find((group) => group.id === student.homeClass);

                    return item ? item.title : '';
                },

                // Tick or untick all students that are shown
                toggleAll(checked) {
                    this.selected = checked ? this.listRecords.map((record) => record.id) : [];
                },

                /**
                 * Put the ticked students into the chosen learning group or take them out of it.
                 * Every student is saved on its own, students that are in (or out) already are skipped.
                 */
                async assignGroup(add) {
                    const group = this.bulkGroup;
                    const students = this.selectedVisible.filter((record) => add !== (record.learningGroups || []).includes(group));

                    const error = await this.run(async () => {
                        for (const record of students) {
                            await this.saveStudentGroup(record, group, add);
                        }
                    });

                    // Also after an error: some students may have been saved
                    await this.loadMasterData();

                    if (null === error) {
                        this.selected = [];
                    } else {
                        // Loading the list has cleared the message
                        this.error = error instanceof ApiError ? error.code : 'general';
                    }
                },

                /**
                 * Ask in a dialog before something is deleted.
                 *
                 * @param {string}   name what is deleted, shown in the question (may be empty)
                 * @param {Function} run  deletes it
                 */
                askDelete(name, run) {
                    const text = String(name || '').replace(/\s+/g, ' ').trim();

                    this.confirm = {name: text.length > 60 ? text.slice(0, 60).trimEnd() + '…' : text, run: run};

                    // "Cancel" has the focus, so that the enter key does not delete
                    this.$nextTick(() => this.$refs.confirmNo && this.$refs.confirmNo.focus());
                },

                async confirmYes() {
                    const run = this.confirm.run;

                    this.confirm = null;
                    await run();
                },

                // How a record of a list is called in the question
                recordName(record) {
                    if ('student' === this.resource) {
                        return [record.firstname, record.lastname].filter(Boolean).join(' ');
                    }

                    const name = Object.keys(this.visibleFields)[0];

                    return name ? this.formatValue(record[name], this.fields[name], name) : '';
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
                        location: '',
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
                    // A finger moves entries with the touch events below, not with the drag of the browser
                    if (this.touch) {
                        event.preventDefault();

                        return;
                    }

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

                // ---------------------------------------------------------------
                // Swipe: a finger that moves sideways for at least 5 cm goes to the next
                // or previous week (week view) or day (day view)
                // ---------------------------------------------------------------

                onSwipeStart(event) {
                    const views = ['week', 'day'];

                    this.swipe = null;

                    if (1 !== event.touches.length || !views.includes(this.view) || this.entryForm || this.openEntries || this.confirm) {
                        return;
                    }

                    // Not from a form field, and only inside the week table or the day list
                    if (event.target.closest('input, select, textarea') || !event.target.closest('.cb-view')) {
                        return;
                    }

                    // A box that scrolls sideways (the week table on a narrow screen) keeps its
                    // scrolling: the swipe only counts in a direction in which the box is at its end
                    const box = event.target.closest('.cb-scroll');
                    const scrollable = null !== box && box.scrollWidth > box.clientWidth + 1;

                    this.swipe = {
                        x: event.touches[0].clientX,
                        y: event.touches[0].clientY,
                        back: !scrollable || box.scrollLeft <= 0,
                        forward: !scrollable || box.scrollLeft >= box.scrollWidth - box.clientWidth - 1,
                    };
                },

                onSwipeEnd(event) {
                    const swipe = this.swipe;

                    this.swipe = null;

                    // Not while an entry is moved with the finger
                    if (null === swipe || null !== this.drag || this.loading || 0 === event.changedTouches.length) {
                        return;
                    }

                    const x = event.changedTouches[0].clientX - swipe.x;
                    const y = event.changedTouches[0].clientY - swipe.y;

                    // Long enough and clearly sideways
                    if (Math.abs(x) < SWIPE_DISTANCE || Math.abs(y) > Math.abs(x) / 2) {
                        return;
                    }

                    // The finger moves to the left: forward, like turning a page
                    const step = x < 0 ? 1 : -1;

                    if ((1 === step && !swipe.forward) || (-1 === step && !swipe.back)) {
                        return;
                    }

                    if ('day' === this.view) {
                        this.shiftDay(step);
                    } else {
                        this.shiftWeeks(step);
                    }
                },

                // ---------------------------------------------------------------
                // Moving entries with a finger: browsers do not start a drag from a
                // touch reliably. Hold an entry, then move it; a short touch scrolls
                // the page or opens the entry as before.
                // ---------------------------------------------------------------

                onTouchStart(entry, event) {
                    if (1 !== event.touches.length || event.target.closest('input, button')) {
                        return;
                    }

                    const point = event.touches[0];

                    this.endTouch();

                    this.touch = {
                        entry: entry,
                        element: event.currentTarget,
                        x: point.clientX,
                        y: point.clientY,
                        ghost: null,
                        scroll: null,
                        timer: window.setTimeout(() => this.startTouchDrag(), TOUCH_HOLD),
                        move: (moveEvent) => this.onTouchMove(moveEvent),
                        end: (endEvent) => this.onTouchEnd(endEvent, true),
                        cancel: (cancelEvent) => this.onTouchEnd(cancelEvent, false),
                        menu: (menuEvent) => menuEvent.preventDefault(),
                    };

                    // Not passive: the move must be able to stop the page from scrolling
                    document.addEventListener('touchmove', this.touch.move, {passive: false});
                    document.addEventListener('touchend', this.touch.end, {passive: false});
                    document.addEventListener('touchcancel', this.touch.cancel);
                    document.addEventListener('contextmenu', this.touch.menu);
                },

                // The finger has rested on the entry long enough: the entry follows the finger
                startTouchDrag() {
                    const touch = this.touch;
                    const box = touch.element.getBoundingClientRect();
                    const ghost = touch.element.cloneNode(true);

                    ghost.removeAttribute('draggable');
                    ghost.classList.add('cb-entry--ghost');
                    ghost.style.width = box.width + 'px';

                    // Inside the app, so that the colours of the theme apply
                    element.appendChild(ghost);

                    touch.timer = null;
                    touch.ghost = ghost;
                    touch.offsetX = touch.x - box.left;
                    touch.offsetY = touch.y - box.top;

                    this.moveGhost();
                    this.drag = {entry: touch.entry, target: null};

                    if (navigator.vibrate) {
                        navigator.vibrate(15);
                    }
                },

                moveGhost() {
                    const touch = this.touch;

                    touch.ghost.style.transform = 'translate(' + (touch.x - touch.offsetX) + 'px, ' + (touch.y - touch.offsetY) + 'px)';
                },

                onTouchMove(event) {
                    const touch = this.touch;
                    const point = event.touches[0];

                    if (null === touch.ghost) {
                        // The finger moves before the entry is picked up: the user scrolls
                        if (Math.abs(point.clientX - touch.x) > TOUCH_TOLERANCE || Math.abs(point.clientY - touch.y) > TOUCH_TOLERANCE) {
                            this.endTouch();
                        }

                        return;
                    }

                    event.preventDefault();

                    touch.x = point.clientX;
                    touch.y = point.clientY;

                    this.moveGhost();
                    this.updateTouchTarget();
                    this.scrollWhileTouching();
                },

                // The slot or the entry below the finger, like onDragOverSlot() and onDragOverEntry()
                updateTouchTarget() {
                    const touch = this.touch;
                    const below = document.elementFromPoint(touch.x, touch.y);
                    const entryElement = below ? below.closest('[data-cb-entry]') : null;
                    const slotElement = below ? below.closest('[data-cb-slot-date]') : null;
                    const entry = entryElement ? this.entries.find((item) => String(item.id) === entryElement.dataset.cbEntry) : null;

                    if (entry) {
                        const box = entryElement.getBoundingClientRect();

                        this.drag.target = {date: entry.entryDate, period: entry.period, entryId: entry.id, after: touch.y > box.top + box.height / 2};
                    } else if (slotElement) {
                        this.drag.target = {date: slotElement.dataset.cbSlotDate, period: Number(slotElement.dataset.cbSlotPeriod), entryId: null, after: true};
                    } else {
                        this.drag.target = null;
                    }
                },

                // Near an edge of the window the page scrolls, and the week table sideways
                scrollWhileTouching() {
                    const touch = this.touch;
                    const speed = (position, size) => position < TOUCH_EDGE ? -TOUCH_SPEED : (position > size - TOUCH_EDGE ? TOUCH_SPEED : 0);
                    const step = () => {
                        if (touch !== this.touch) {
                            return;
                        }

                        const x = speed(touch.x, window.innerWidth);
                        const y = speed(touch.y, window.innerHeight);
                        const table = element.querySelector('.cb-scroll');

                        if (0 === x && 0 === y) {
                            touch.scroll = null;

                            return;
                        }

                        window.scrollBy(0, y);

                        if (table) {
                            table.scrollLeft += x;
                        }

                        this.updateTouchTarget();

                        touch.scroll = window.requestAnimationFrame(step);
                    };

                    if (null === touch.scroll) {
                        touch.scroll = window.requestAnimationFrame(step);
                    }
                },

                onTouchEnd(event, drop) {
                    const dragging = this.touch && null !== this.touch.ghost;

                    this.endTouch();

                    if (!dragging) {
                        return;
                    }

                    // No click afterwards, it would open the entry
                    if (event.cancelable) {
                        event.preventDefault();
                    }

                    if (drop) {
                        this.onDrop();
                    } else {
                        this.drag = null;
                    }
                },

                endTouch() {
                    const touch = this.touch;

                    if (!touch) {
                        return;
                    }

                    window.clearTimeout(touch.timer);
                    window.cancelAnimationFrame(touch.scroll);

                    document.removeEventListener('touchmove', touch.move);
                    document.removeEventListener('touchend', touch.end);
                    document.removeEventListener('touchcancel', touch.cancel);
                    document.removeEventListener('contextmenu', touch.menu);

                    if (touch.ghost) {
                        touch.ghost.remove();
                    }

                    this.touch = null;
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
                    this.focusFirstField('.cb-dialog--lesson');
                },

                // Off-canvas navigation: open it and move the focus into it
                openNav() {
                    this.navOpen = true;
                    this.userMenu = false;

                    this.$nextTick(() => {
                        const current = element.querySelector('.cb-tabs .cb-tab--active') || element.querySelector('.cb-tabs .cb-tab');

                        if (current) {
                            current.focus();
                        }
                    });
                },

                // A click on any of its buttons closes the off-canvas navigation
                onNavClick(event) {
                    if (event.target.closest('button')) {
                        this.navOpen = false;
                    }
                },

                // The text below a field of a generic form, e.g. that the date of birth is optional
                fieldHint(name) {
                    return (this.labels.fieldHints || {})[name] || '';
                },

                // A lesson without a room takes the classroom of the chosen class or learning group
                onLessonGroupChange() {
                    const form = this.lessonForm;
                    const group = this.master.data.group.find((item) => item.id === form.data.learningGroup);
                    const classroom = group ? this.master.data.classroom.find((item) => item.id === group.classroom) : null;

                    if ('' === form.data.room && classroom) {
                        form.data.room = classroom.title;
                    }
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

                // What a column is sorted by: numbers as numbers, dates as stored (YYYY-MM-DD),
                // everything else as it is shown (e.g. the title of the class)
                sortKey(record, name) {
                    const field = this.fields[name];
                    const value = record[name];

                    if ('integer' === field.type || 'date' === field.type || 'time' === field.type) {
                        return value;
                    }

                    return 'color' === field.type ? this.colorName(value, field, name) : this.formatValue(value, field, name);
                },

                // How a column is sorted: its level (1 counts most, 0 = not sorted) and direction
                // list: MEMBER_LIST for the students of a learning group, else the open list
                sortState(name, list) {
                    const levels = this.levelsOf(list);
                    const index = levels.findIndex((level) => level.field === name);

                    if (index < 0) {
                        return {level: 0, arrow: '', aria: null};
                    }

                    const desc = levels[index].desc;

                    return {level: index + 1, arrow: desc ? '↓' : '↑', aria: desc ? 'descending' : 'ascending'};
                },

                // A click on a column: ascending, then descending, then not sorted any more.
                // A column that is not sorted yet becomes the next level.
                toggleSort(name, list) {
                    const levels = this.levelsOf(list).map((level) => ({...level}));
                    const index = levels.findIndex((level) => level.field === name);

                    if (index < 0) {
                        levels.push({field: name, desc: false});
                    } else if (!levels[index].desc) {
                        levels[index].desc = true;
                    } else {
                        levels.splice(index, 1);
                    }

                    this.storeSort(levels, list);
                },

                resetSort(list) {
                    this.storeSort([], list);
                },

                levelsOf(list) {
                    return MEMBER_LIST === list ? this.memberSortLevels : this.sortLevels;
                },

                storeSort(levels, list) {
                    this.sort = {...this.sort, [list || this.resource]: levels};

                    try {
                        window.localStorage.setItem(SORT_KEY, JSON.stringify(this.sort));
                    } catch (error) {
                        // Without storage the order is forgotten with the page
                    }
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
            applyTheme(element, getInitialTheme());

            // The login page: the next login starts with the week, not where somebody was
            if (element.hasAttribute('data-cb-login')) {
                clearState();
            }
        });
    };

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
