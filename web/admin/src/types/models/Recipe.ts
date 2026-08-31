/**
 * Recipe Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Recipe as RecipeBase } from './base/Recipe';
import {
  baseRecipeSchemas,
  baseRecipeCreateSchema,
  baseRecipeUpdateSchema,
  recipeI18n,
  getRecipeLabel,
  getRecipeFieldLabel,
  getRecipeFieldPlaceholder,
} from './base/Recipe';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Recipe extends RecipeBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const recipeSchemas = { ...baseRecipeSchemas };
export const recipeCreateSchema = baseRecipeCreateSchema;
export const recipeUpdateSchema = baseRecipeUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RecipeCreate = z.infer<typeof recipeCreateSchema>;
export type RecipeUpdate = z.infer<typeof recipeUpdateSchema>;

// Re-export i18n and helpers
export {
  recipeI18n,
  getRecipeLabel,
  getRecipeFieldLabel,
  getRecipeFieldPlaceholder,
};

// Re-export base type for internal use
export type { RecipeBase };
