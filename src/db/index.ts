import { drizzle } from 'drizzle-orm/postgres-js';
import postgres from 'postgres';
import * as schema from './schema.js';

const DATABASE_URL = process.env.REGISTRAR_DATABASE_URL || process.env.DATABASE_URL;
if (!DATABASE_URL) throw new Error('REGISTRAR_DATABASE_URL or DATABASE_URL must be set');

const client = postgres(DATABASE_URL, { max: 10 });
export const db = drizzle(client, { schema });
export { schema };
