import { getDocument } from 'pdfjs-dist';
import fs from 'fs';

const pdfPath = "C:\\Users\\Wendulka\\Documents\\Webhry\\hollyhop\\broker\\Výpisy\\Revolut\\vaclav 12.pdf";

try {
    const data = new Uint8Array(fs.readFileSync(pdfPath));
    const loadingTask = getDocument({ data });
    const pdf = await loadingTask.promise;

    console.log(`PDF loaded, pages: ${pdf.numPages}`);
    let fullText = '';
    for (let i = 1; i <= pdf.numPages; i++) {
        const page = await pdf.getPage(i);
        const textContent = await page.getTextContent();
        // @ts-ignore
        const strings = textContent.items.map(item => item.str);
        fullText += strings.join(' ') + '\n';
    }

    console.log('--- START TEXT ---');
    console.log(fullText);
    console.log('--- END TEXT ---');
} catch (e) {
    console.error(e);
}
