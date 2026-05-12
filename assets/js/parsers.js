function stiEscapeHTML(str) {

    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function stiNormalizeRows(rows) {

    let maxCols = 0;

    rows.forEach(row => {

        if (row.length > maxCols) {
            maxCols = row.length;
        }

    });

    return rows.map(row => {

        while (row.length < maxCols) {
            row.push('');
        }

        return row;
    });
}

function stiGenerateTable(rows) {

    rows = stiNormalizeRows(rows);

    let html = '<table>';  // class="wp-table-imported"

    rows.forEach((row, rowIndex) => {

        html += '<tr>';

        row.forEach(cell => {

            let tag = rowIndex === 0 ? 'th' : 'td';

            html += '<' + tag + '>';
            html += stiEscapeHTML(cell.trim());
            html += '</' + tag + '>';

        });

        html += '</tr>';

    });

    html += '</table>';

    return html;
}

function stiParseTSV(input) {

    let rows = input
        .trim()
        .split('\n')
        .map(row => row.split('\t'));

    return stiGenerateTable(rows);
}

function stiParseCSV(input) {

    let parsed = Papa.parse(input.trim());

    return stiGenerateTable(parsed.data);
}

function stiParsePipe(input) {

    let rows = input
        .trim()
        .split('\n')
        .map(row => {

            return row
                .split('|')

                // remove empty edge columns
                .filter((cell, index, arr) => {

                    // remove first empty
                    if (
                        index === 0 &&
                        cell.trim() === ''
                    ) {
                        return false;
                    }

                    // remove last empty
                    if (
                        index === arr.length - 1 &&
                        cell.trim() === ''
                    ) {
                        return false;
                    }

                    return true;
                })

                .map(cell => cell.trim());

        })

        // remove markdown separator row
        .filter(row => {

            return !row.every(cell =>
                /^:?-+:?$/.test(cell)
            );

        });

    return stiGenerateTable(rows);
}

function stiSanitizeTable(table) {

    table.querySelectorAll('*').forEach(el => {

        [...el.attributes].forEach(attr => {

            if (
                ![
                    'rowspan',
                    'colspan',
                    'style',
                    'align',
                    'width',
                    'height'
                ].includes(attr.name)
            ) {
                el.removeAttribute(attr.name);
            }

        });

    });

    return table;
}

function stiParseHTMLTables(input) {

    const parser = new DOMParser();

    const doc = parser.parseFromString(
        input,
        'text/html'
    );

    let output = '';

    doc.querySelectorAll('table').forEach(table => {

        stiSanitizeTable(table);

        //table.classList.add('wp-table-imported');

        output += table.outerHTML;

    });

    return output;
}

function stiLooksLikeCSV(input) {

    let lines = input.trim().split('\n');

    if (!lines.length) {
        return false;
    }

    return lines[0].includes(',');
}

function stiDetectAndConvert(input) {

    if (input.includes('<table')) {
        return stiParseHTMLTables(input);
    }

    if (input.includes('\t')) {
        return stiParseTSV(input);
    }

    if (stiLooksLikeCSV(input)) {
        return stiParseCSV(input);
    }

    if (input.includes('|')) {
        return stiParsePipe(input);
    }

    return false;
}