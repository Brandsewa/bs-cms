/**
 * QR Code Generator (Reed-Solomon & QR spec implementation)
 * Pure TypeScript, zero external dependencies, minimal footprint.
 * Supports Byte mode (UTF-8) with Error Correction Level L.
 */

// Simple Galois Field (GF(256)) math with primitive polynomial 0x11d
const EXP_TABLE = new Uint8Array(512);
const LOG_TABLE = new Uint8Array(256);

(() => {
  let x = 1;
  for (let i = 0; i < 255; i++) {
    EXP_TABLE[i] = x;
    EXP_TABLE[i + 255] = x;
    LOG_TABLE[x] = i;
    x <<= 1;
    if (x & 0x100) x ^= 0x11d;
  }
})();

function gfMul(x: number, y: number): number {
  if (x === 0 || y === 0) return 0;
  const logX = LOG_TABLE[x] ?? 0;
  const logY = LOG_TABLE[y] ?? 0;
  return EXP_TABLE[logX + logY] ?? 0;
}

function polyMul(p1: number[], p2: number[]): number[] {
  const result = new Array<number>(p1.length + p2.length - 1).fill(0);
  for (let i = 0; i < p1.length; i++) {
    for (let j = 0; j < p2.length; j++) {
      const idx = i + j;
      const val = result[idx] ?? 0;
      result[idx] = val ^ gfMul(p1[i] ?? 0, p2[j] ?? 0);
    }
  }
  return result;
}

function getGeneratorPoly(numEcBytes: number): number[] {
  let g = [1];
  for (let i = 0; i < numEcBytes; i++) {
    g = polyMul(g, [1, EXP_TABLE[i] ?? 0]);
  }
  return g;
}

function calculateEcBytes(data: number[], numEcBytes: number): number[] {
  const gen = getGeneratorPoly(numEcBytes);
  const remainder = new Array<number>(numEcBytes).fill(0);
  for (let i = 0; i < data.length; i++) {
    const factor = (data[i] ?? 0) ^ (remainder[0] ?? 0);
    for (let j = 0; j < numEcBytes - 1; j++) {
      remainder[j] = (remainder[j + 1] ?? 0) ^ gfMul(gen[j + 1] ?? 0, factor);
    }
    remainder[numEcBytes - 1] = gfMul(gen[numEcBytes] ?? 0, factor);
  }
  return remainder;
}

interface VersionConfig {
  ver: number;
  size: number;
  totalCw: number;
  ecCw: number;
  dataCw: number;
}

const VERSION_DATA: VersionConfig[] = [
  { ver: 1, size: 21, totalCw: 26, ecCw: 7, dataCw: 19 },
  { ver: 2, size: 25, totalCw: 44, ecCw: 10, dataCw: 34 },
  { ver: 3, size: 29, totalCw: 70, ecCw: 15, dataCw: 55 },
  { ver: 4, size: 33, totalCw: 100, ecCw: 20, dataCw: 80 },
  { ver: 5, size: 37, totalCw: 134, ecCw: 26, dataCw: 108 },
  { ver: 6, size: 41, totalCw: 172, ecCw: 36, dataCw: 136 },
  { ver: 7, size: 45, totalCw: 196, ecCw: 40, dataCw: 156 },
  { ver: 8, size: 49, totalCw: 242, ecCw: 48, dataCw: 194 },
];

export function generateQrMatrix(text: string): boolean[][] {
  const textBytes = Array.from(new TextEncoder().encode(text));

  // Find minimal version
  let config: VersionConfig = VERSION_DATA[0]!;
  for (const v of VERSION_DATA) {
    const overhead = v.ver < 10 ? 2 : 3;
    if (textBytes.length + overhead <= v.dataCw) {
      config = v;
      break;
    }
  }

  // Bitstream creation (Byte mode: 0100)
  const bitStream: number[] = [];
  const pushBits = (val: number, len: number) => {
    for (let i = len - 1; i >= 0; i--) {
      bitStream.push((val >> i) & 1);
    }
  };

  // Mode 4 = 0100 (Byte mode)
  pushBits(0b0100, 4);
  // Character count indicator (8 bits for ver 1-9)
  pushBits(textBytes.length, 8);
  for (const b of textBytes) {
    pushBits(b, 8);
  }
  // Terminator
  const maxBits = config.dataCw * 8;
  const termLen = Math.min(4, Math.max(0, maxBits - bitStream.length));
  pushBits(0, termLen);
  // Pad to byte boundary
  while (bitStream.length % 8 !== 0) {
    bitStream.push(0);
  }
  // Pad bytes 0xEC, 0x11
  let padToggle = false;
  while (bitStream.length < maxBits) {
    pushBits(padToggle ? 0x11 : 0xec, 8);
    padToggle = !padToggle;
  }

  // Convert bits to data codewords
  const dataCodewords: number[] = [];
  for (let i = 0; i < config.dataCw; i++) {
    let b = 0;
    for (let j = 0; j < 8; j++) {
      b = (b << 1) | (bitStream[i * 8 + j] ?? 0);
    }
    dataCodewords.push(b);
  }

  // Error correction
  const ecCodewords = calculateEcBytes(dataCodewords, config.ecCw);

  // Combine data + EC
  const finalCodewords = [...dataCodewords, ...ecCodewords];

  // Matrix allocation
  const n = config.size;
  const matrix: (boolean | null)[][] = Array.from({ length: n }, () =>
    new Array<boolean | null>(n).fill(null)
  );
  const isFunction: boolean[][] = Array.from({ length: n }, () =>
    new Array<boolean>(n).fill(false)
  );

  const setFunc = (r: number, c: number, v: boolean) => {
    const row = matrix[r];
    const fRow = isFunction[r];
    if (row && fRow) {
      row[c] = v;
      fRow[c] = true;
    }
  };

  // Finder pattern helper
  const addFinder = (row: number, col: number) => {
    for (let r = -1; r <= 7; r++) {
      for (let c = -1; c <= 7; c++) {
        const nr = row + r;
        const nc = col + c;
        if (nr >= 0 && nr < n && nc >= 0 && nc < n) {
          if (r >= 0 && r <= 6 && c >= 0 && c <= 6) {
            const isBlack =
              r === 0 || r === 6 || c === 0 || c === 6 || (r >= 2 && r <= 4 && c >= 2 && c <= 4);
            setFunc(nr, nc, isBlack);
          } else {
            setFunc(nr, nc, false); // separator
          }
        }
      }
    }
  };

  // Add 3 Finder Patterns
  addFinder(0, 0);
  addFinder(0, n - 7);
  addFinder(n - 7, 0);

  // Timing patterns
  for (let i = 8; i < n - 8; i++) {
    setFunc(6, i, i % 2 === 0);
    setFunc(i, 6, i % 2 === 0);
  }

  // Dark module
  setFunc(4 * config.ver + 9, 8, true);

  // Format info reserve
  for (let i = 0; i < 9; i++) {
    if (!isFunction[8]?.[i]) setFunc(8, i, false);
    if (!isFunction[i]?.[8]) setFunc(i, 8, false);
  }
  for (let i = 0; i < 8; i++) {
    if (!isFunction[8]?.[n - 1 - i]) setFunc(8, n - 1 - i, false);
    if (!isFunction[n - 1 - i]?.[8]) setFunc(n - 1 - i, 8, false);
  }

  // Place Data Codewords (zigzag up/down)
  let bitIdx = 0;
  const totalBits = config.totalCw * 8;
  const getNextBit = () => {
    if (bitIdx >= totalBits) return 0;
    const cw = finalCodewords[Math.floor(bitIdx / 8)] ?? 0;
    const bit = (cw >> (7 - (bitIdx % 8))) & 1;
    bitIdx++;
    return bit;
  };

  let upwards = true;
  for (let right = n - 1; right > 0; right -= 2) {
    if (right === 6) right--; // skip timing column
    for (let vert = 0; vert < n; vert++) {
      const r = upwards ? n - 1 - vert : vert;
      for (let c = right; c >= right - 1; c--) {
        const row = matrix[r];
        if (row && !isFunction[r]?.[c]) {
          const bit = getNextBit();
          // Mask 0: (row + col) % 2 === 0
          const mask = (r + c) % 2 === 0;
          row[c] = (bit === 1) !== mask;
        }
      }
    }
    upwards = !upwards;
  }

  // Format string for Mask 0 + EC L: 010001111010110
  const formatBits = [0, 1, 0, 0, 0, 1, 1, 1, 1, 0, 1, 0, 1, 1, 0];

  const setFormat = (r: number, c: number, v: boolean) => {
    const row = matrix[r];
    if (row) row[c] = v;
  };

  // Place format info
  for (let i = 0; i < 6; i++) setFormat(8, i, formatBits[i] === 1);
  setFormat(8, 7, formatBits[6] === 1);
  setFormat(8, 8, formatBits[7] === 1);
  setFormat(7, 8, formatBits[8] === 1);
  for (let i = 9; i < 15; i++) setFormat(14 - i, 8, formatBits[i] === 1);

  for (let i = 0; i < 8; i++) setFormat(8, n - 1 - i, formatBits[14 - i] === 1);
  for (let i = 0; i < 7; i++) setFormat(n - 7 + i, 8, formatBits[i] === 1);

  return matrix.map((row) => row.map((cell) => cell ?? false));
}

export interface QrCodeDisplayProps {
  value: string;
  size?: number;
  className?: string;
  ariaLabel?: string;
}

export function QrCodeDisplay({
  value,
  size = 180,
  className = '',
  ariaLabel = 'Two factor authentication QR code',
}: QrCodeDisplayProps) {
  try {
    const matrix = generateQrMatrix(value);
    const n = matrix.length;
    const margin = 2; // quiet zone
    const viewBoxSize = n + margin * 2;

    return (
      <svg
        role="img"
        aria-label={ariaLabel}
        viewBox={`0 0 ${viewBoxSize} ${viewBoxSize}`}
        width={size}
        height={size}
        className={`rounded bg-white p-2 text-black ${className}`}
        style={{ shapeRendering: 'crispEdges' }}
      >
        <rect width="100%" height="100%" fill="white" />
        {matrix.map((row, r) =>
          row.map((cell, c) =>
            cell ? (
              <rect
                key={`${r}-${c}`}
                x={c + margin}
                y={r + margin}
                width={1}
                height={1}
                fill="black"
              />
            ) : null
          )
        )}
      </svg>
    );
  } catch {
    return (
      <div
        className="flex h-[180px] w-[180px] items-center justify-center rounded border border-border bg-muted text-xs text-muted-foreground"
        role="img"
        aria-label={ariaLabel}
      >
        QR preview unavailable
      </div>
    );
  }
}
