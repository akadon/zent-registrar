import type { FastifyInstance } from 'fastify';
import { db, schema } from '../db/index.js';
import { eq, ilike, and, or, sql, desc, asc } from 'drizzle-orm';
import crypto from 'crypto';
