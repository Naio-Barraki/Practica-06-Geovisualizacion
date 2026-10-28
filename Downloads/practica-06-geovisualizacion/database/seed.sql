-- Datos iniciales para probar catálogo, A/B, historial, mediciones y reportes.
USE practica06_geovisualizacion;
SET NAMES utf8mb4;

INSERT IGNORE INTO especies (id,nombre,nombre_cientifico,categoria,tasa_base,capacidad_carga,altura_max_cm,biomasa_max_g,temp_min,temp_max,temp_opt,agua_opt,luz_opt,nutrientes_opt,co2_opt,dias_madurez,color_hex,descripcion) VALUES
(1,'Maíz','Zea mays','Cereal',0.1000,1200,220,460,18,32,25,78,82,70,450,105,'#69a65f','Cereal de crecimiento rápido; el agua disponible durante las etapas reproductivas afecta el rendimiento.'),
(2,'Frijol','Phaseolus vulgaris','Leguminosa',0.0900,900,150,260,16,30,24,72,70,62,430,75,'#70ad74','Leguminosa con fijación biológica de nitrógeno en simbiosis; responde al estrés hídrico.'),
(3,'Tomate','Solanum lycopersicum','Hortaliza',0.0850,1000,180,390,17,30,23,75,76,78,450,90,'#4b9b65','Hortaliza cuyo rendimiento depende de temperatura, riego y nutrición.'),
(4,'Arabidopsis thaliana','Arabidopsis thaliana','Planta modelo',0.1200,600,35,42,16,27,22,58,66,55,420,42,'#82b96e','Planta modelo de investigación utilizada para estudiar genética y desarrollo vegetal.'),
(5,'Girasol','Helianthus annuus','Oleaginosa',0.0950,1100,210,410,18,34,26,66,88,68,440,100,'#69a85f','Especie con alta demanda de luz; se usa para explorar rendimiento y madurez.'),
(6,'Lavanda','Lavandula angustifolia','Aromática',0.0650,800,85,180,12,30,21,38,80,45,420,120,'#65986c','Aromática adaptada a sustratos bien drenados y requerimientos moderados de agua.');

INSERT IGNORE INTO plantas (id,especie_id,identificador,alias,notas) VALUES
(1,1,'PHY-SEED-MAIZ-A','Maíz de laboratorio · A','Planta virtual de demostración, escenario A.'),
(2,1,'PHY-SEED-MAIZ-B','Maíz de laboratorio · B','Planta virtual de demostración, escenario B.'),
(3,3,'PHY-SEED-TOMATE-A','Tomate de laboratorio · A','Planta virtual de demostración, escenario A.');

INSERT IGNORE INTO simulaciones (id,nombre,notas,especie_id,planta_id,modelo,escenario_label,parametros,dias_simulados,altura_final,biomasa_final,hojas_finales,ramas_finales,icr_final,estres_hidrico,estres_termico,estres_luminico,estres_nutricional,estres_co2,estres_total,dias_cosecha_estimados,estado,created_at) VALUES
(1,'Maíz · escenario de referencia','Semilla de demostración; riego y luz cercanos al óptimo.',1,1,'logistico','A','{"rate":0.10,"K":1200,"days":90,"environment":{"light":82,"water":78,"temperature":25,"nutrients":70,"co2":450}}',90,1.91,382.5,43,7,1.24,0,0,0,0,0,0,84,'completada','2026-10-10 10:00:00'),
(2,'Maíz · riego optimizado','Semilla de demostración para comparar una alternativa con mayor disponibilidad de agua.',1,2,'gompertz','B','{"rate":0.10,"K":1200,"days":90,"environment":{"light":82,"water":90,"temperature":24,"nutrients":70,"co2":450}}',90,2.04,411.2,45,7,0.96,33,8,0,0,0,15,78,'completada','2026-10-10 10:05:00'),
(3,'Tomate · condición templada','Corrida demostrativa con estrés térmico leve y horizonte de 75 días.',3,3,'exponencial','A','{"rate":0.085,"K":1000,"days":75,"environment":{"light":75,"water":70,"temperature":27,"nutrients":78,"co2":440}}',75,1.42,286.4,37,6,1.53,14,40,3,0,3,16,73,'completada','2026-10-11 09:20:00');

INSERT IGNORE INTO condiciones_ambientales (simulacion_id,luz_pct,agua_pct,temperatura_c,nutrientes_pct,co2_ppm,viento,tasa_crecimiento,capacidad_carga) VALUES
(1,82,78,25,70,450,0.25,0.10,1200),
(2,82,90,24,70,450,0.25,0.10,1200),
(3,75,70,27,78,440,0.20,0.085,1000);

-- Simulación 1: 7 puntos temporales precargados.
INSERT IGNORE INTO mediciones (simulacion_id,dia,altura_u,biomasa_g,n_hojas,n_ramas,icr,progreso_pct,estres_hidrico,estres_termico,estres_luminico,estres_nutricional,estres_co2,estres_total) VALUES
(1,0,0.06,1.9,0,0,0,1.5,0,0,0,0,0,0),
(1,15,0.22,6.4,3,1,8.2,10.0,0,0,0,0,0,0),
(1,30,0.61,36.8,12,2,7.1,31.0,0,0,0,0,0,0),
(1,45,1.10,112.5,24,4,5.6,56.0,0,0,0,0,0,0),
(1,60,1.53,225.3,34,6,3.1,76.0,0,0,0,0,0,0),
(1,75,1.78,323.8,40,7,1.8,88.0,0,0,0,0,0,0),
(1,90,1.91,382.5,43,7,1.24,94.0,0,0,0,0,0,0),
-- Simulación 2: alternativa de riego.
(2,0,0.07,2.3,0,0,0,1.8,33,8,0,0,0,15),
(2,15,0.28,9.4,4,1,9.4,13.0,33,8,0,0,0,15),
(2,30,0.72,48.1,15,3,8.0,36.0,33,8,0,0,0,15),
(2,45,1.25,139.4,27,5,6.1,62.0,33,8,0,0,0,15),
(2,60,1.68,254.8,37,6,3.4,81.0,33,8,0,0,0,15),
(2,75,1.91,350.5,42,7,2.0,91.0,33,8,0,0,0,15),
(2,90,2.04,411.2,45,7,0.96,97.0,33,8,0,0,0,15),
-- Simulación 3: tomate.
(3,0,0.04,1.2,0,0,0,1.4,14,40,3,0,3,16),
(3,15,0.16,4.8,2,1,9.1,8.0,14,40,3,0,3,16),
(3,30,0.48,25.4,9,2,7.8,25.0,14,40,3,0,3,16),
(3,45,0.86,78.1,18,3,5.2,45.0,14,40,3,0,3,16),
(3,60,1.20,175.3,29,5,3.6,65.0,14,40,3,0,3,16),
(3,75,1.42,286.4,37,6,1.53,82.0,14,40,3,0,3,16);

INSERT IGNORE INTO decisiones (id,simulacion_id,regla,severidad,mensaje,recomendacion,metrica,valor,umbral,created_at) VALUES
(1,1,'Riego dentro del rango','good','La disponibilidad de agua se encuentra cerca del óptimo simulado para maíz.','Mantener el régimen actual y continuar registrando humedad.','agua',78,78,'2026-10-10 10:00:00'),
(2,1,'Madurez próxima','good','El modelo alcanza el umbral de madurez dentro del horizonte de referencia.','Programar una evaluación de madurez real antes de cosechar.','madurez',84,90,'2026-10-10 10:00:00'),
(3,2,'Comparación de riego','warning','El escenario tiene un índice hídrico mayor por desviación sobre el óptimo.','No asumir que más agua siempre mejora el rendimiento; revisar drenaje y datos reales.','agua',90,78,'2026-10-10 10:05:00'),
(4,3,'Alerta térmica','warning','La temperatura se encuentra varios grados por encima del óptimo de referencia del tomate.','Evaluar ventilación o sombreado y confirmar con sensores.','temperatura',27,23,'2026-10-11 09:20:00');


-- Óptimos de referencia para análisis geoespacial (valores educativos aproximados, no prescripciones agronómicas).
UPDATE especies SET precip_opt_mm_year=800, solar_opt_kwh_m2_day=5.5, ph_opt=6.5, soil_pref='franco profundo' WHERE id=1;
UPDATE especies SET precip_opt_mm_year=700, solar_opt_kwh_m2_day=5.0, ph_opt=6.3, soil_pref='franco bien drenado' WHERE id=2;
UPDATE especies SET precip_opt_mm_year=650, solar_opt_kwh_m2_day=5.2, ph_opt=6.2, soil_pref='franco, rico en materia orgánica' WHERE id=3;
UPDATE especies SET precip_opt_mm_year=600, solar_opt_kwh_m2_day=4.5, ph_opt=6.5, soil_pref='franco con buen drenaje' WHERE id=4;
UPDATE especies SET precip_opt_mm_year=550, solar_opt_kwh_m2_day=6.0, ph_opt=6.5, soil_pref='franco bien drenado' WHERE id=5;
UPDATE especies SET precip_opt_mm_year=550, solar_opt_kwh_m2_day=5.8, ph_opt=6.8, soil_pref='ligero y bien drenado' WHERE id=6;
